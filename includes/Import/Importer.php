<?php
/**
 * Turns imported rows into organizations, members and locations.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Import;

use WooOrgAccounts\Admin\Settings;
use WooOrgAccounts\Data\Location;
use WooOrgAccounts\Data\LocationRepository;
use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Data\MemberRepository;
use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Data\OrganizationRepository;
use WooOrgAccounts\Membership\Context;
use WooOrgAccounts\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * The write side of the import.
 *
 * One instance runs one batch. It carries the organization index and a little state
 * about the organizations this run has touched, which is what lets the second row of
 * a company join the organization the first row created rather than make another.
 *
 * **A preview and a real import are the same code.** `$dry_run` decides whether the
 * saves happen; everything else — the grouping, the roles, the location de-duplication,
 * the reasons a row is skipped — runs identically, so the figures somebody approves on
 * the preview screen are the figures the import produces. A preview written as its own
 * pass would be written against the same assumptions as the importer and would agree
 * with it right up to the point where one of them was wrong.
 *
 * **Nothing here sends email.** Not the shop's new-account mail, not this plugin's own
 * new-organization notice: an import is a migration of customers who already exist, and
 * six hundred of them being told they have a new account they did not ask for is not a
 * recoverable mistake. Accounts are created with a random password nobody holds, and
 * the shop invites people to set one in its own time.
 */
final class Importer {

	/**
	 * The options the run is using.
	 *
	 * @var array
	 */
	private $options;

	/**
	 * Whether to work everything out and write nothing.
	 *
	 * @var bool
	 */
	private $dry_run;

	/**
	 * Organization key to organization ID.
	 *
	 * @var array
	 */
	private $index = array();

	/**
	 * What this run knows about each organization it has touched.
	 *
	 * @var array
	 */
	private $state = array();

	/**
	 * The accounts this run has already made, keyed by lowercased email address.
	 *
	 * A preview writes nothing, so `email_exists()` cannot see the accounts it has
	 * decided to create — and without this, a file naming one address twice would be
	 * previewed as two accounts and imported as one. Kept during a real run too, where
	 * it saves the lookup rather than changes the answer.
	 *
	 * @var array
	 */
	private $accounts = array();

	/**
	 * How many organizations this instance has created.
	 *
	 * Counted here rather than derived from the outcomes, because the row that creates
	 * an organization is not always the row that creates an account — an existing
	 * WordPress user attached to a brand new organization reports as linked, and
	 * counting the created rows would miss the organization entirely.
	 *
	 * @var int
	 */
	private $organizations_created = 0;

	/**
	 * The next pseudo-ID handed out during a preview.
	 *
	 * Negative, so an ID that has escaped from a preview into anything that writes is
	 * obvious rather than plausible.
	 *
	 * @var int
	 */
	private $next_pending_id = -1;

	/**
	 * Constructor.
	 *
	 * @param array $options Import options; see Run::default_options().
	 * @param bool  $dry_run True to work out every answer and write nothing.
	 */
	public function __construct( array $options = array(), $dry_run = false ) {
		$this->options = wp_parse_args( $options, Run::default_options() );
		$this->dry_run = (bool) $dry_run;

		$this->index = OrganizationKey::index( ! empty( $this->options['ignore_legal_form'] ) );
	}

	/**
	 * Import one row.
	 *
	 * @param Record $record The row.
	 * @return Result What happened to it.
	 */
	public function import( Record $record ) {
		$result = new Result( $record );

		if ( ! $record->is_importable() ) {
			return $result->set_action( Result::FAILED );
		}

		$email    = $record->email();
		$existing = $this->existing_account( $email );
		$key      = $record->organization_key();

		/*
		 * A user who is already a member of an organization is left exactly as they
		 * are. Moving them would be the one destructive thing an import could do — the
		 * membership row is what every order they have placed is scoped by — so the row
		 * is reported instead, and somebody decides.
		 */

		/*
		 * Compared against zero rather than tested as positive: a preview hands out
		 * negative pseudo-IDs for the rows it has decided to create, and `> 0` quietly
		 * reads every one of them as "no organization" — which made a preview of a file
		 * naming one address twice report two new accounts where the import made one.
		 */
		if ( null !== $existing && 0 !== (int) $existing['organization_id'] ) {
			return $this->handle_existing_member( $record, $result, $existing, $key );
		}

		$was_created     = false;
		$user_id         = null === $existing ? 0 : $existing['user_id'];
		$organization_id = $this->find_or_create_organization( $record, $result, $key, $was_created );

		if ( 0 === $organization_id ) {
			return $result->set_action( Result::FAILED, __( 'The organization could not be saved.', 'woo-organization-accounts-pro' ) );
		}

		$result->set_organization_id( $organization_id );

		$role = $this->role_for( $organization_id );

		if ( $user_id > 0 ) {
			$this->adopt_existing_user( $user_id, $role, $result );
			$result->set_action( Result::LINKED );
		} else {
			$user_id = $this->create_user( $record, $role );

			if ( is_wp_error( $user_id ) ) {
				$this->roll_back_organization( $organization_id );

				return $result->set_action( Result::FAILED, $user_id->get_error_message() )->set_organization_id( 0 );
			}

			$result->set_action( $was_created ? Result::CREATED : Result::JOINED );
		}

		$result->set_user_id( $user_id );

		if ( ! $this->create_membership( $organization_id, $user_id, $role ) ) {
			return $result->set_action( Result::FAILED, __( 'The membership could not be saved.', 'woo-organization-accounts-pro' ) );
		}

		++$this->state[ $organization_id ]['members'];

		$this->accounts[ strtolower( $email ) ] = array(
			'user_id'         => (int) $user_id,
			'organization_id' => (int) $organization_id,
		);

		$this->add_location( $record, $result, $organization_id );

		return $result;
	}

	/**
	 * Deal with a row whose email already belongs to a member of an organization.
	 *
	 * Two different situations wear the same shape. The address belonging to the
	 * organization this row maps to is a row that has been imported already — a second
	 * run of the same file, or the same person exported twice — and the only thing left
	 * to do is contribute the delivery address, in case this copy of the row carries one
	 * the first did not. The address belonging to a *different* organization is the one
	 * case an import genuinely cannot answer: two organizations claim one person, and
	 * WordPress will only let them be in one. That is reported and left alone.
	 *
	 * @param Record $record   The row.
	 * @param Result $result   The outcome to fill in.
	 * @param array  $existing The account already holding this email address.
	 * @param string $key      The organization key the row maps to.
	 * @return Result The outcome.
	 */
	private function handle_existing_member( Record $record, Result $result, array $existing, $key ) {
		$organization_id = (int) $existing['organization_id'];

		$result->set_user_id( (int) $existing['user_id'] )->set_organization_id( $organization_id );

		if ( '' !== $key && isset( $this->index[ $key ] ) && $this->index[ $key ] === $organization_id ) {
			$result->set_action( Result::SKIPPED, __( 'This account has been imported already.', 'woo-organization-accounts-pro' ) );

			$this->add_location( $record, $result, $organization_id );

			return $result;
		}

		return $result->set_action(
			Result::SKIPPED,
			sprintf(
				/* translators: 1: email address. 2: name of the organization the address already belongs to. */
				__( '%1$s already belongs to "%2$s", so this row was left alone. Two organizations claim this person and only one of them can have them.', 'woo-organization-accounts-pro' ),
				$record->email(),
				$this->organization_name( $organization_id )
			)
		);
	}

	/**
	 * What an organization is called, whether or not it has been written yet.
	 *
	 * @param int $organization_id Organization ID.
	 * @return string Name, falling back to the ID when there is nothing to read.
	 */
	private function organization_name( $organization_id ) {
		if ( ! empty( $this->state[ $organization_id ]['name'] ) ) {
			return (string) $this->state[ $organization_id ]['name'];
		}

		$organization = $organization_id > 0 ? OrganizationRepository::find( $organization_id ) : null;

		return null === $organization ? (string) $organization_id : $organization->get_name();
	}

	/**
	 * Find the organization a row belongs to, creating it when there is none.
	 *
	 * `$was_created` answers for *this row*, not for the run. The state carried against
	 * an organization says whether the import created it at some point, which is the
	 * right question for whether its status may still be corrected and the wrong one for
	 * what to call this row's outcome — reading it for both reported every colleague
	 * after the first as another new account.
	 *
	 * @param Record $record      The row.
	 * @param Result $result      The outcome to note things on.
	 * @param string $key         The organization key.
	 * @param bool   $was_created Set to true when this row is the one that created it.
	 * @return int Organization ID, or 0 when it could not be written.
	 */
	private function find_or_create_organization( Record $record, Result $result, $key, &$was_created ) {
		$was_created = false;

		if ( '' !== $key && isset( $this->index[ $key ] ) ) {
			$organization_id = $this->index[ $key ];

			$this->prime_state( $organization_id );
			$this->maybe_reactivate( $record, $organization_id );

			return $organization_id;
		}

		$organization = new Organization();
		$organization->set_props(
			array(
				'name'                  => $record->organization_name(),
				'tax_id'                => $record->tax_id(),
				'status'                => $record->organization_status(),
				'allow_custom_shipping' => (bool) Settings::get( 'default_allow_custom_shipping', true ),
			)
		);
		$organization->set_billing_address( $record->billing_address() );

		if ( $this->dry_run ) {
			$organization_id = $this->next_pending_id--;
		} else {
			$organization_id = OrganizationRepository::save( $organization );

			if ( 0 === $organization_id ) {
				return 0;
			}
		}

		if ( '' !== $key ) {
			$this->index[ $key ] = $organization_id;
		}

		$this->state[ $organization_id ] = array(
			'created'   => true,
			'name'      => $organization->get_name(),
			'status'    => $organization->get_status(),
			'members'   => 0,
			'locations' => array(),
		);

		if ( ! $this->dry_run ) {
			/**
			 * Fires after an import has created an organization.
			 *
			 * Deliberately not `woo_org_accounts_organization_registered`: that one is
			 * what tells the shop somebody has signed up, and a migration is not six
			 * hundred sign-ups.
			 *
			 * @since 0.7.0
			 *
			 * @param Organization $organization The new organization.
			 * @param Record       $record       The row it was created from.
			 */
			do_action( 'woo_org_accounts_organization_imported', $organization, $record );
		}

		++$this->organizations_created;
		$was_created = true;

		return $organization_id;
	}

	/**
	 * How many organizations this instance has created.
	 *
	 * @return int Count.
	 */
	public function organizations_created() {
		return $this->organizations_created;
	}

	/**
	 * Bring a suspended organization back if a later row of the same import is active.
	 *
	 * A shop exports one row per person, so an organization's status arrives several
	 * times over and the copies disagree — one employee who has left, one who has not.
	 * Any active row means the account is live, and taking the first row's answer would
	 * lock out a working customer because a former colleague's login was closed.
	 *
	 * Only organizations this run created. An organization that was already on the site
	 * has a status somebody chose, and an import is not the thing that overrules it.
	 *
	 * @param Record $record          The row.
	 * @param int    $organization_id Organization ID.
	 * @return void
	 */
	private function maybe_reactivate( Record $record, $organization_id ) {
		if ( empty( $this->state[ $organization_id ]['created'] ) || ! $record->is_active() ) {
			return;
		}

		if ( Organization::STATUS_ACTIVE === $this->state[ $organization_id ]['status'] ) {
			return;
		}

		$this->state[ $organization_id ]['status'] = Organization::STATUS_ACTIVE;

		if ( $this->dry_run ) {
			return;
		}

		$organization = OrganizationRepository::find( $organization_id );

		if ( null !== $organization ) {
			$organization->set( 'status', Organization::STATUS_ACTIVE );
			OrganizationRepository::save( $organization );
		}
	}

	/**
	 * The membership role this row's account takes.
	 *
	 * @param int $organization_id Organization ID.
	 * @return string One of the Member::ROLE_* constants.
	 */
	private function role_for( $organization_id ) {
		$this->prime_state( $organization_id );

		if ( 'first_admin' !== $this->options['role_mode'] ) {
			return Member::ROLE_ADMIN;
		}

		return 0 === $this->state[ $organization_id ]['members'] ? Member::ROLE_ADMIN : Member::ROLE_MEMBER;
	}

	/**
	 * Create the WordPress user for a row.
	 *
	 * The password is random and nobody is told it, which is the whole of this
	 * plugin's answer to an export that carries no passwords: the account exists, the
	 * orders attach to it, and the customer sets a password through the shop's ordinary
	 * lost-password form whenever the shop invites them to.
	 *
	 * @param Record $record The row.
	 * @param string $role   Membership role, which decides the WordPress role.
	 * @return int|\WP_Error User ID, or an error.
	 */
	private function create_user( Record $record, $role ) {
		if ( $this->dry_run ) {
			return $this->next_pending_id--;
		}

		$user_id = wp_insert_user(
			array(
				'user_login' => $record->email(),
				'user_email' => $record->email(),
				'user_pass'  => wp_generate_password( 32, true, true ),
				'first_name' => $record->first_name(),
				'last_name'  => $record->last_name(),
				'role'       => Roles::wordpress_role( $role ),
			)
		);

		return $user_id;
	}

	/**
	 * Give an existing WordPress user the role their membership needs.
	 *
	 * Anybody who can manage the shop is left exactly as they are. An administrator or
	 * a shop manager who also buys on an organization's account is a real arrangement,
	 * and `set_role()` replaces every role a user holds — so demoting one of them to an
	 * organization member is how an import locks the shop's own staff out of wp-admin.
	 *
	 * @param int    $user_id User ID.
	 * @param string $role    Membership role.
	 * @param Result $result  The outcome to note things on.
	 * @return void
	 */
	private function adopt_existing_user( $user_id, $role, Result $result ) {
		$result->add_note( __( 'A WordPress account with this address already existed and was added to the organization.', 'woo-organization-accounts-pro' ) );

		if ( user_can( $user_id, 'manage_woocommerce' ) ) {
			$result->add_note( __( 'Their WordPress role was left alone, because they can manage the shop.', 'woo-organization-accounts-pro' ) );

			return;
		}

		if ( $this->dry_run ) {
			return;
		}

		$user = get_user_by( 'id', $user_id );

		if ( $user instanceof \WP_User ) {
			$user->set_role( Roles::wordpress_role( $role ) );
		}
	}

	/**
	 * Write the membership linking a user to an organization.
	 *
	 * No capability overrides are stored. They are a diff against the role's defaults,
	 * so an empty one means "whatever the role allows" — which is what an imported
	 * account should have, and is not the same as storing every capability off.
	 *
	 * @param int    $organization_id Organization ID.
	 * @param int    $user_id         User ID.
	 * @param string $role            Membership role.
	 * @return bool True when it was written.
	 */
	private function create_membership( $organization_id, $user_id, $role ) {
		if ( $this->dry_run ) {
			return true;
		}

		$member = new Member();
		$member->set_props(
			array(
				'organization_id' => $organization_id,
				'user_id'         => $user_id,
				'role'            => $role,
				'status'          => Member::STATUS_ACTIVE,
			)
		);

		$saved = MemberRepository::save( $member ) > 0;

		Context::flush();

		return $saved;
	}

	/**
	 * Add the row's delivery address to its organization, unless it is there already.
	 *
	 * Compared on every field rather than on the four the organization key uses: two
	 * addresses in one building that differ by a floor or by who receives the goods are
	 * two places a parcel goes, and collapsing them loses one of them for good.
	 *
	 * @param Record $record          The row.
	 * @param Result $result          The outcome to count on.
	 * @param int    $organization_id Organization ID.
	 * @return void
	 */
	private function add_location( Record $record, Result $result, $organization_id ) {
		$address = $record->delivery_address();

		if ( '' === trim( $address['address_1'] ) && '' === trim( $address['city'] ) ) {
			return;
		}

		$this->prime_state( $organization_id );

		/*
		 * The same fallback the account screen applies when somebody saves a location by
		 * hand: a parcel with no company on the label is one nobody at a loading bay
		 * recognises.
		 */
		if ( '' === trim( (string) $address['company'] ) ) {
			$address['company'] = $record->organization_name();
		}

		$key = OrganizationKey::for_location( $address );

		if ( in_array( $key, $this->state[ $organization_id ]['locations'], true ) ) {
			return;
		}

		$this->state[ $organization_id ]['locations'][] = $key;

		if ( ! $this->dry_run ) {
			$location = new Location();
			$location->set_props(
				array(
					'organization_id' => $organization_id,
					'name'            => $record->location_name(),
					'is_default'      => 1 === count( $this->state[ $organization_id ]['locations'] ),
				)
			);
			$location->set_shipping_address( $address );

			if ( 0 === LocationRepository::save( $location ) ) {
				$result->add_note( __( 'The delivery address could not be saved.', 'woo-organization-accounts-pro' ) );

				return;
			}
		}

		$result->count_location();
	}

	/**
	 * Remove an organization this row created but could not finish populating.
	 *
	 * The same rule registration follows: an organization with no members cannot be
	 * reached, cannot buy and cannot be repaired by the person it was made for, so
	 * leaving one behind is worse than reporting the failure.
	 *
	 * @param int $organization_id Organization ID.
	 * @return void
	 */
	private function roll_back_organization( $organization_id ) {
		if ( empty( $this->state[ $organization_id ]['created'] ) || $this->state[ $organization_id ]['members'] > 0 ) {
			return;
		}

		unset( $this->state[ $organization_id ] );
		--$this->organizations_created;

		$key = array_search( $organization_id, $this->index, true );

		if ( false !== $key ) {
			unset( $this->index[ $key ] );
		}

		if ( ! $this->dry_run ) {
			OrganizationRepository::delete( $organization_id );
		}
	}

	/**
	 * Load what this run needs to know about an organization it has not touched yet.
	 *
	 * @param int $organization_id Organization ID.
	 * @return void
	 */
	private function prime_state( $organization_id ) {
		if ( isset( $this->state[ $organization_id ] ) ) {
			return;
		}

		$locations = array();

		if ( $organization_id > 0 ) {
			foreach ( LocationRepository::for_organization( $organization_id ) as $location ) {
				$locations[] = OrganizationKey::for_location( $location->get_shipping_address() );
			}
		}

		$this->state[ $organization_id ] = array(
			'created'   => false,
			'name'      => '',
			'status'    => '',
			'members'   => $organization_id > 0 ? MemberRepository::count_for_organization( $organization_id ) : 0,
			'locations' => $locations,
		);
	}

	/**
	 * The account already holding an email address, and the organization it is in.
	 *
	 * @param string $email Email address.
	 * @return array|null Map with user_id and organization_id, or null when the address
	 *                    belongs to nobody. An organization_id of 0 means the user
	 *                    exists but is a member of nothing.
	 */
	private function existing_account( $email ) {
		$lower = strtolower( $email );

		if ( isset( $this->accounts[ $lower ] ) ) {
			return $this->accounts[ $lower ];
		}

		$user_id = email_exists( $email );

		if ( false === $user_id ) {
			return null;
		}

		$membership = MemberRepository::find_by_user( (int) $user_id );

		return array(
			'user_id'         => (int) $user_id,
			'organization_id' => null === $membership ? 0 : $membership->get_organization_id(),
		);
	}
}
