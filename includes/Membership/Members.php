<?php
/**
 * Putting people on an organization's account, and taking them off it.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Membership;

use WooOrgAccounts\Data\LocationRepository;
use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Data\MemberRepository;
use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Labels;
use WooOrgAccounts\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * The one expression of what adding, changing and removing a member does.
 *
 * Three screens write a membership — the account screens a customer uses, the REST routes a
 * back office uses, and wp-admin — and until this class existed the first two carried their
 * own copy of every rule below. They had already drifted: the account screens demoted an
 * administrator who happened to be a member of an organization, because they called
 * `set_role()` directly where the REST route knew to leave shop staff alone. A third copy
 * for wp-admin would have drifted again, and the rules here are ones where drifting is not
 * a cosmetic difference.
 *
 * `Members\Invitations` is the shape this follows: static methods taking scalars and arrays
 * rather than a request object, returning the entity or a `WP_Error`, and leaving every
 * decision about *presentation* to the caller. That split is why the errors here are keyed
 * by a canonical field name — `email`, `role`, `capabilities`, `location_access` — and carry
 * no HTTP status. `Rest\Writes::refuse()` turns those into a REST refusal and `Rest\
 * MembersController` renames the conflict codes onto the wire; a screen prefixes them
 * `woap_` and marks the field. Neither shape belongs in here.
 *
 * What is deliberately *not* here is the question a form asks and an API does not: whether
 * somebody ticking "only these branches" ticked any. That is a question about a control,
 * its wording depends on the control, and each caller asks it before calling in. What is
 * here is the rule underneath — an empty stored list means *every* location, so an empty
 * list is never what gets stored.
 */
final class Members {

	/**
	 * The value of `capabilities` meaning "whatever the role allows".
	 */
	const ROLE_DEFAULT = 'role_default';

	/**
	 * The value of `location_access` meaning every location the organization has.
	 */
	const ACCESS_ALL = 'all';

	/**
	 * That address already belongs to an organization.
	 */
	const ERROR_ALREADY_MEMBER = 'woap_already_member';

	/**
	 * That address has a WordPress account of its own.
	 */
	const ERROR_EMAIL_TAKEN = 'woap_email_taken';

	/**
	 * The organization would be left with nobody able to administer it.
	 */
	const ERROR_LAST_ADMIN = 'woap_last_admin';

	/**
	 * The membership points at a user account that is not there.
	 */
	const ERROR_NO_USER = 'woap_no_user';

	/**
	 * The database would not take the write.
	 */
	const ERROR_NOT_SAVED = 'woap_not_saved';

	/**
	 * Put somebody on the account, creating or adopting their WordPress user.
	 *
	 * This is the "create" half of adding somebody — staff entering an employee rather than
	 * inviting them. The invitation half is `Members\Invitations::create()`, and the two are
	 * not variants of one act: an invitation has no membership row for permissions or
	 * location access to land on until it is accepted.
	 *
	 * An existing account is adopted rather than duplicated, and one that already belongs to
	 * an organization is refused rather than moved — the membership row is what every order
	 * that person has placed is scoped by, and `woap_members.user_id` is UNIQUE, so the
	 * attempt would fail at the database anyway and say so far less clearly.
	 *
	 * The password of a created account is random and nobody is told it. The person reaches
	 * the account through the shop's own lost-password form, which is the importer's answer
	 * to the same problem. Nothing is emailed from here.
	 *
	 * @param Organization $organization The organization to add them to.
	 * @param array        $values       email (required), role, first_name, last_name,
	 *                                   capabilities, location_access. A key that is absent
	 *                                   is not set rather than set empty.
	 * @return Member|\WP_Error The membership, or a refusal.
	 */
	public static function add( Organization $organization, array $values ) {
		$email  = sanitize_email( (string) ( $values['email'] ?? '' ) );
		$role   = self::role( $values['role'] ?? Member::ROLE_MEMBER );
		$errors = new \WP_Error();

		if ( '' === $email || ! is_email( $email ) ) {
			$errors->add( 'email', __( 'Please enter a valid email address.', 'woo-organization-accounts-pro' ) );
		}

		$capabilities = self::resolve_capabilities( $values, $role, $errors );
		$locations    = self::resolve_locations( $values, $organization->get_id(), $errors );

		if ( $errors->has_errors() ) {
			return $errors;
		}

		$user     = get_user_by( 'email', $email );
		$existing = $user instanceof \WP_User ? MemberRepository::find_by_user( $user->ID ) : null;

		if ( null !== $existing ) {
			return self::already_a_member( $existing, $organization->get_id() );
		}

		$user_id = $user instanceof \WP_User
			? $user->ID
			: self::create_user( $email, $role, $values );

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$member = new Member();
		$member->set_props(
			array(
				'organization_id' => $organization->get_id(),
				'user_id'         => $user_id,
				'role'            => $role,
				'status'          => Member::STATUS_ACTIVE,
			)
		);

		$member->set_capabilities( null === $capabilities ? array() : $capabilities );

		if ( 0 === MemberRepository::save( $member ) ) {
			return self::not_saved(
				sprintf(
					/* translators: %s: the singular member noun for the site's mode, for example "Employee". */
					__( 'That %s could not be saved.', 'woo-organization-accounts-pro' ),
					Labels::member()
				)
			);
		}

		if ( null !== $locations ) {
			MemberRepository::set_location_ids( $member->get_id(), $locations );
		}

		self::apply_wordpress_role( $user_id, Roles::wordpress_role( $role ) );

		return $member;
	}

	/**
	 * Change somebody's name, address, role, status, permissions or location access.
	 *
	 * Only what `$changes` mentions is changed, which is what lets one screen edit a role
	 * and another edit a surname without either blanking what it said nothing about.
	 *
	 * The order of the writes is deliberate and was a bug first. Everything that can be
	 * refused is settled before anything is written, and the WordPress account goes first of
	 * the two: a refusal there is an ordinary thing a caller meets — the address is somebody
	 * else's — whereas a repository answering 0 is a database anomaly. The other way round
	 * leaves somebody promoted to admin by a request that then failed on the address it also
	 * carried.
	 *
	 * @param Member $member  The membership to change.
	 * @param array  $changes first_name, last_name, email, role, status, capabilities,
	 *                        location_access. Absent means "leave it alone".
	 * @return Member|\WP_Error The membership, or a refusal.
	 */
	public static function update( Member $member, array $changes ) {
		$role   = array_key_exists( 'role', $changes ) ? self::role( $changes['role'] ) : $member->get_role();
		$status = array_key_exists( 'status', $changes ) ? self::status( $changes['status'] ) : (string) $member->get( 'status' );
		$errors = new \WP_Error();

		$capabilities = self::resolve_capabilities( $changes, $role, $errors );
		$locations    = self::resolve_locations( $changes, $member->get_organization_id(), $errors );

		$losing_admin = $member->is_admin() && ( Member::ROLE_ADMIN !== $role || Member::STATUS_ACTIVE !== $status );

		if ( $losing_admin && ! MemberRepository::has_other_admin( $member->get_organization_id(), $member->get_id() ) ) {
			$errors->add( 'role', self::last_admin_message() );
		}

		if ( $errors->has_errors() ) {
			return $errors;
		}

		$refusal = self::update_identity( $member, $changes );

		if ( $refusal instanceof \WP_Error ) {
			return $refusal;
		}

		$member->set( 'role', $role );
		$member->set( 'status', $status );

		if ( null !== $capabilities ) {
			$member->set_capabilities( $capabilities );
		}

		if ( 0 === MemberRepository::save( $member ) ) {
			return self::not_saved(
				sprintf(
					/* translators: %s: the singular member noun for the site's mode, for example "Employee". */
					__( 'That %s could not be saved.', 'woo-organization-accounts-pro' ),
					Labels::member()
				)
			);
		}

		if ( null !== $locations ) {
			MemberRepository::set_location_ids( $member->get_id(), $locations );
		}

		self::apply_wordpress_role( $member->get_user_id(), Roles::wordpress_role( $role ) );

		return $member;
	}

	/**
	 * Take somebody off the account.
	 *
	 * The WordPress account survives — deleting somebody's login because they changed jobs
	 * is not this plugin's decision — and moves to WooCommerce's customer role, so with no
	 * membership row it can no longer buy on the organization's account.
	 *
	 * @param Member $member The membership to end.
	 * @return true|\WP_Error True, or a refusal.
	 */
	public static function remove( Member $member ) {
		if ( $member->is_admin() && ! MemberRepository::has_other_admin( $member->get_organization_id(), $member->get_id() ) ) {
			return new \WP_Error( self::ERROR_LAST_ADMIN, self::last_admin_message() );
		}

		$user_id = $member->get_user_id();

		if ( ! MemberRepository::delete( $member->get_id() ) ) {
			return self::not_saved(
				sprintf(
					/* translators: %s: the singular member noun for the site's mode, for example "Employee". */
					__( 'That %s could not be removed.', 'woo-organization-accounts-pro' ),
					Labels::member()
				)
			);
		}

		self::apply_wordpress_role( $user_id, 'customer' );

		return true;
	}

	/**
	 * Reduce an absolute permission map to the overrides worth storing.
	 *
	 * Overrides are a **diff against the role's defaults**, so they are meaningless without
	 * knowing which role they are a diff against — and the role they are against is the one
	 * being saved, never the one held before the change. Deriving them against the old role
	 * is how promoting an employee to organization admin once stored "everything off" as six
	 * overrides and produced an admin who could manage nothing.
	 *
	 * @param string $role   The role being saved.
	 * @param array  $wanted Map of capability to boolean: what should be true of this member.
	 * @return array Only the capabilities that differ from the role's own answer.
	 */
	public static function capability_overrides( $role, array $wanted ) {
		$defaults  = Roles::role_capabilities( $role );
		$overrides = array();

		foreach ( Roles::capabilities() as $capability ) {
			$granted = array_key_exists( $capability, $wanted )
				? (bool) $wanted[ $capability ]
				: (bool) $defaults[ $capability ];

			if ( $granted !== (bool) $defaults[ $capability ] ) {
				$overrides[ $capability ] = $granted;
			}
		}

		return $overrides;
	}

	/**
	 * The refusal an organization gives when it would be left with nobody to run it.
	 *
	 * @return string The message.
	 */
	public static function last_admin_message() {
		return sprintf(
			/* translators: 1: the organization noun, 2: the organization admin noun. */
			__( 'A %1$s must keep at least one active %2$s. Promote somebody else first.', 'woo-organization-accounts-pro' ),
			Labels::organization(),
			Labels::organization_admin()
		);
	}

	/**
	 * Give a user the WordPress role their membership needs, unless they run the shop.
	 *
	 * `set_role()` replaces every role a user holds, so an administrator or a shop manager
	 * who also buys on an organization's account would be demoted out of wp-admin by a
	 * routine membership edit. They are left exactly as they are; their capabilities within
	 * the organization come from the membership row regardless.
	 *
	 * @param int    $user_id User to move.
	 * @param string $role    WordPress role name.
	 * @return void
	 */
	public static function apply_wordpress_role( $user_id, $role ) {
		if ( user_can( $user_id, 'manage_woocommerce' ) ) {
			return;
		}

		$user = get_user_by( 'id', $user_id );

		if ( $user instanceof \WP_User ) {
			$user->set_role( $role );
		}
	}

	/**
	 * The overrides a caller asks for, or null to leave them alone.
	 *
	 * @param array     $values Whatever the caller passed.
	 * @param string    $role   The role being saved, which the diff is against.
	 * @param \WP_Error $errors Errors to add to.
	 * @return array|null Overrides to store, or null.
	 */
	private static function resolve_capabilities( array $values, $role, \WP_Error $errors ) {
		if ( ! array_key_exists( 'capabilities', $values ) ) {
			return null;
		}

		$requested = $values['capabilities'];

		if ( self::ROLE_DEFAULT === $requested ) {
			return array();
		}

		if ( ! is_array( $requested ) ) {
			$errors->add(
				'capabilities',
				sprintf(
					/* translators: %s: the literal string "role_default". */
					__( 'Send "%s", or an object naming capabilities that should differ from the role.', 'woo-organization-accounts-pro' ),
					self::ROLE_DEFAULT
				)
			);

			return null;
		}

		$unknown = array_diff( array_keys( $requested ), Roles::capabilities() );

		if ( ! empty( $unknown ) ) {
			$errors->add(
				'capabilities',
				sprintf(
					/* translators: 1: comma-separated list of capability names, 2: comma-separated list of the capabilities that exist. */
					__( 'No such capability: %1$s. The capabilities are %2$s.', 'woo-organization-accounts-pro' ),
					implode( ', ', $unknown ),
					implode( ', ', Roles::capabilities() )
				)
			);

			return null;
		}

		return self::capability_overrides( $role, $requested );
	}

	/**
	 * The location access a caller asks for, or null to leave it alone.
	 *
	 * An empty list is refused rather than stored, because the stored form of "every
	 * location" *is* an empty list — so a caller sending `[]` meaning "none" would get the
	 * opposite of what it asked for, silently. `"all"` is the way to say every location, and
	 * there is deliberately no way to say none: somebody who may order but may ship nowhere
	 * cannot check out, and that is what the membership status is for.
	 *
	 * Every ID is checked against the organization it is being stored for. That is the
	 * cross-tenant question, and it is separate from whether the caller holds a capability.
	 *
	 * @param array     $values          Whatever the caller passed.
	 * @param int       $organization_id The organization the locations must belong to.
	 * @param \WP_Error $errors          Errors to add to.
	 * @return array|null Location IDs, an empty array for "all", or null.
	 */
	private static function resolve_locations( array $values, $organization_id, \WP_Error $errors ) {
		if ( ! array_key_exists( 'location_access', $values ) ) {
			return null;
		}

		$requested = $values['location_access'];

		if ( self::ACCESS_ALL === $requested ) {
			return array();
		}

		if ( ! is_array( $requested ) || empty( $requested ) ) {
			$errors->add(
				'location_access',
				sprintf(
					/* translators: 1: the literal string "all", 2: the plural location noun for the site's mode. */
					__( 'Send "%1$s", or a non-empty list of %2$s to restrict this person to.', 'woo-organization-accounts-pro' ),
					self::ACCESS_ALL,
					Labels::locations()
				)
			);

			return null;
		}

		$ids = array();

		foreach ( $requested as $id ) {
			$id = absint( $id );

			if ( null === LocationRepository::find_for_organization( $id, $organization_id ) ) {
				$errors->add(
					'location_access',
					sprintf(
						/* translators: 1: the singular location noun, for example "Branch", 2: the organization noun, 3: a location ID. */
						__( 'No %1$s of this %2$s has the identifier %3$d.', 'woo-organization-accounts-pro' ),
						Labels::location(),
						Labels::organization(),
						$id
					)
				);

				continue;
			}

			$ids[] = $id;
		}

		return $errors->has_errors() ? null : array_values( array_unique( $ids ) );
	}

	/**
	 * Create the WordPress account for somebody being entered by staff.
	 *
	 * @param string $email  The address to create the account for.
	 * @param string $role   Membership role, which decides the WordPress role.
	 * @param array  $values Whatever else the caller passed.
	 * @return int|\WP_Error User ID, or an error.
	 */
	private static function create_user( $email, $role, array $values ) {
		return wp_insert_user(
			array(
				'user_login' => $email,
				'user_email' => $email,
				'user_pass'  => wp_generate_password( 32, true, true ),
				'first_name' => sanitize_text_field( (string) ( $values['first_name'] ?? '' ) ),
				'last_name'  => sanitize_text_field( (string) ( $values['last_name'] ?? '' ) ),
				'role'       => Roles::wordpress_role( $role ),
			)
		);
	}

	/**
	 * Write the name and the address an edit carries onto the WordPress account.
	 *
	 * Neither is stored on the membership row — `woap_members` has no column for either — so
	 * this is the whole of it: `wp_update_user()` rather than a direct meta write, because
	 * WooCommerce keeps its own customer record in step by listening to `profile_update` and
	 * a member is a customer of the shop.
	 *
	 * The fields an edit does not mention are read back off the account rather than left
	 * out, because the display name is derived from all three together and deriving it from
	 * half of them would blank a surname the change never mentioned.
	 *
	 * @param Member $member  The membership being edited.
	 * @param array  $changes What the caller asked to change.
	 * @return \WP_Error|null A refusal, or null when there was nothing to do or it is done.
	 */
	private static function update_identity( Member $member, array $changes ) {
		$submitted = array();

		foreach ( array( 'first_name', 'last_name', 'email' ) as $field ) {
			if ( array_key_exists( $field, $changes ) ) {
				$submitted[ $field ] = ( 'email' === $field )
					? sanitize_email( (string) $changes[ $field ] )
					: sanitize_text_field( (string) $changes[ $field ] );
			}
		}

		if ( empty( $submitted ) ) {
			return null;
		}

		$user = get_userdata( $member->get_user_id() );

		if ( ! $user instanceof \WP_User ) {
			return new \WP_Error(
				self::ERROR_NO_USER,
				__( 'The account behind this membership no longer exists.', 'woo-organization-accounts-pro' )
			);
		}

		$identity = array_merge(
			array(
				'first_name' => (string) $user->first_name,
				'last_name'  => (string) $user->last_name,
				'email'      => (string) $user->user_email,
			),
			$submitted
		);

		$conflict = self::address_conflict( $user, $identity['email'], $member->get_organization_id() );

		if ( $conflict instanceof \WP_Error ) {
			return $conflict;
		}

		$updated = wp_update_user(
			array(
				'ID'           => $user->ID,
				'user_email'   => $identity['email'],
				'first_name'   => $identity['first_name'],
				'last_name'    => $identity['last_name'],
				'display_name' => self::display_name( $user, $identity ),
			)
		);

		return is_wp_error( $updated ) ? $updated : null;
	}

	/**
	 * Whether an address an edit asks for belongs to somebody else.
	 *
	 * WordPress refuses the write itself, with `existing_user_email` and nothing to act on.
	 * Asking first is what lets the answer say *where* that address already is, which for an
	 * address on another organization's account is the one fact needed to resolve it — and
	 * it is the same refusal as adding somebody who already belongs to an organization,
	 * because it is the same rule.
	 *
	 * @param \WP_User $user            The account being edited.
	 * @param string   $email           The address the edit asks for.
	 * @param int      $organization_id The organization the membership belongs to.
	 * @return \WP_Error|null A refusal, or null when the address is free.
	 */
	private static function address_conflict( \WP_User $user, $email, $organization_id ) {
		if ( 0 === strcasecmp( $email, (string) $user->user_email ) ) {
			return null;
		}

		$owner = get_user_by( 'email', $email );

		if ( ! $owner instanceof \WP_User || $owner->ID === $user->ID ) {
			return null;
		}

		$existing = MemberRepository::find_by_user( $owner->ID );

		if ( null !== $existing ) {
			return self::already_a_member( $existing, $organization_id );
		}

		return new \WP_Error(
			self::ERROR_EMAIL_TAKEN,
			__( 'That address already has an account on this shop. One address belongs to one account, so it cannot be moved onto this one.', 'woo-organization-accounts-pro' ),
			array(
				'user_id' => $owner->ID,
				'params'  => array( 'email' => __( 'Already in use.', 'woo-organization-accounts-pro' ) ),
			)
		);
	}

	/**
	 * The display name an account should hold once an edit has landed.
	 *
	 * Every screen in this plugin prints `display_name` — the members list, the member form,
	 * the organization orders list, the order column in wp-admin — so a rename that left it
	 * alone would be a field with no destination: stored, served back, and visible nowhere
	 * anybody looks.
	 *
	 * **A display name somebody has set by hand is left exactly as it is.** It is only
	 * overwritten when it is still one of the things this plugin or WordPress would have
	 * derived it from, which is what keeps a shop's own correction from being undone by a
	 * routine edit to a surname.
	 *
	 * @param \WP_User $user     The account as it stands.
	 * @param array    $identity The name and address it is about to hold.
	 * @return string The display name to store.
	 */
	private static function display_name( \WP_User $user, array $identity ) {
		$derived = trim( $identity['first_name'] . ' ' . $identity['last_name'] );
		$derived = '' !== $derived ? $derived : $identity['email'];
		$stored  = (string) $user->display_name;

		$derivable = array_filter(
			array(
				trim( $user->first_name . ' ' . $user->last_name ),
				(string) $user->user_email,
				(string) $user->user_login,
			)
		);

		return in_array( $stored, $derivable, true ) ? $derived : $stored;
	}

	/**
	 * The refusal for an address that already belongs to an organization.
	 *
	 * @param Member $existing        The membership that address already has.
	 * @param int    $organization_id The organization it was being added to, or edited on.
	 * @return \WP_Error The refusal.
	 */
	private static function already_a_member( Member $existing, $organization_id ) {
		$message = $existing->get_organization_id() === (int) $organization_id
			? sprintf(
				/* translators: %s: the organization noun for the site's mode, for example "Company". */
				__( 'That address already belongs to this %s.', 'woo-organization-accounts-pro' ),
				Labels::organization()
			)
			: sprintf(
				/* translators: %1$s: the organization noun for the site's mode, for example "Company". */
				__( 'That address already belongs to another %1$s. Remove them from it first: a person belongs to one %1$s at a time, and every order they have placed is scoped by that membership.', 'woo-organization-accounts-pro' ),
				Labels::organization()
			);

		return new \WP_Error(
			self::ERROR_ALREADY_MEMBER,
			$message,
			array(
				'organization_id' => $existing->get_organization_id(),
				'member_id'       => $existing->get_id(),
			)
		);
	}

	/**
	 * The refusal for a write the database would not take.
	 *
	 * Every repository here answers a failed write with 0 rather than an exception, and a
	 * caller that ignored that would report success for a row that does not exist.
	 *
	 * @param string $message What could not be done.
	 * @return \WP_Error The refusal.
	 */
	private static function not_saved( $message ) {
		return new \WP_Error( self::ERROR_NOT_SAVED, $message );
	}

	/**
	 * Coerce whatever was asked for to a membership role.
	 *
	 * @param mixed $role The requested role.
	 * @return string One of the Member::ROLE_* constants.
	 */
	private static function role( $role ) {
		return ( Member::ROLE_ADMIN === $role ) ? Member::ROLE_ADMIN : Member::ROLE_MEMBER;
	}

	/**
	 * Coerce whatever was asked for to a membership status.
	 *
	 * @param mixed $status The requested status.
	 * @return string One of the Member::STATUS_* constants.
	 */
	private static function status( $status ) {
		return ( Member::STATUS_INACTIVE === $status ) ? Member::STATUS_INACTIVE : Member::STATUS_ACTIVE;
	}
}
