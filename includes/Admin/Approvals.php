<?php
/**
 * The queue of registrations waiting to be reviewed.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Admin;

use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Data\MemberRepository;
use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Data\OrganizationRepository;
use WooOrgAccounts\Labels;

defined( 'ABSPATH' ) || exit;

/**
 * A screen for deciding, rather than a filter on a list.
 *
 * Approving a registration is the one act in this plugin that a shop performs about a
 * customer it has never dealt with, on evidence it has to read. Until this screen existed it
 * was a row action on a filtered list view — a decision with an email attached, made from a
 * row showing a name and a status pill. The address the customer will be invoiced at, the
 * tax number they gave, and above all *who the person signing up is* were all one click
 * further away than the Approve link was, so the fast path through the screen was the one
 * that read nothing.
 *
 * So this is a card per registration carrying everything the decision needs, and the two
 * buttons at the end of it.
 *
 * **What is approved is a customer account.** The status lives on the organization row —
 * there is one approval here, not two, and no per-member state to keep in step with it — but
 * a shop reviewing a queue is looking at a person who wants to buy, and the screen says so.
 * The person is the organization's first admin: the account that registered.
 *
 * **The decision goes through the same door as everywhere else.** Both buttons are
 * `Organizations::status_url()`, handled by `Organizations::handle_set_status()`, which calls
 * `OrganizationRepository::set_status()` — the one write that fires
 * `woo_org_accounts_organization_status_changed` and therefore the one that sends the
 * approval and rejection mail. A second write path here would be a second answer to what
 * approving means, and the first thing to drift would be the email.
 */
class Approvals {

	/**
	 * Menu slug of the screen.
	 */
	const PAGE_SLUG = 'woo-organization-accounts-approvals';

	/**
	 * Capability required to use it.
	 */
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Add the screen under the plugin's menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			Menu::PAGE_SLUG,
			__( 'Approvals', 'woo-organization-accounts-pro' ),
			$this->menu_title(),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * The menu label, with the pending count beside it.
	 *
	 * @return string The label.
	 */
	private function menu_title() {
		$pending = Menu::pending_count();

		if ( 0 === $pending ) {
			return esc_html__( 'Approvals', 'woo-organization-accounts-pro' );
		}

		return sprintf(
			'%1$s <span class="awaiting-mod"><span class="pending-count">%2$s</span></span>',
			esc_html__( 'Approvals', 'woo-organization-accounts-pro' ),
			esc_html( number_format_i18n( $pending ) )
		);
	}

	/**
	 * Load the stylesheet the review cards need.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'woap-admin', WOAP_PLUGIN_URL . 'assets/css/admin.css', array(), WOAP_VERSION );
	}

	/**
	 * The URL of this screen.
	 *
	 * @return string URL.
	 */
	public static function url() {
		return add_query_arg( array( 'page' => self::PAGE_SLUG ), admin_url( 'admin.php' ) );
	}

	/**
	 * Render the queue.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$pending = OrganizationRepository::query(
			array(
				'status'  => Organization::STATUS_PENDING,
				'orderby' => 'date_created',
				'order'   => 'ASC',
			)
		);

		echo '<div class="wrap woap-approvals">';
		printf( '<h1 class="wp-heading-inline">%s</h1>', esc_html__( 'Approvals', 'woo-organization-accounts-pro' ) );
		echo '<hr class="wp-header-end">';

		if ( empty( $pending ) ) {
			$this->render_empty();
			echo '</div>';

			return;
		}

		printf(
			'<p class="woap-approvals__intro">%s</p>',
			esc_html(
				sprintf(
					/* translators: %d: how many registrations are waiting. */
					_n(
						'%d registration is waiting to be reviewed. Until it is approved, nobody on that account can place an order.',
						'%d registrations are waiting to be reviewed. Until one is approved, nobody on that account can place an order.',
						count( $pending ),
						'woo-organization-accounts-pro'
					),
					count( $pending )
				)
			)
		);

		// The oldest first: a queue is worked from the end somebody has been waiting at.
		foreach ( $pending as $organization ) {
			$this->render_card( $organization );
		}

		echo '</div>';
	}

	/**
	 * Nothing to review.
	 *
	 * Names what is missing and where the rest is, rather than printing "no results" — with
	 * an empty queue this screen is the good news, not a failed search.
	 *
	 * @return void
	 */
	private function render_empty() {
		echo '<div class="woap-empty">';
		printf( '<h2>%s</h2>', esc_html__( 'Nothing waiting', 'woo-organization-accounts-pro' ) );
		printf(
			'<p>%s</p>',
			esc_html__( 'Every registration has been reviewed. New ones arrive here, and everybody on an approved account can order straight away.', 'woo-organization-accounts-pro' )
		);
		printf(
			'<p><a href="%1$s" class="button">%2$s</a></p>',
			esc_url( Organizations::list_url() ),
			esc_html(
				sprintf(
					/* translators: %s: the plural organization noun for the site's mode, for example "Companies". */
					__( 'View all %s', 'woo-organization-accounts-pro' ),
					Labels::organizations()
				)
			)
		);
		echo '</div>';
	}

	/**
	 * One registration, with everything the decision rests on.
	 *
	 * @param Organization $organization The registration.
	 * @return void
	 */
	private function render_card( Organization $organization ) {
		$applicant = $this->applicant( $organization );

		echo '<div class="woap-review">';

		echo '<div class="woap-review__header">';
		printf(
			'<h2 class="woap-review__title"><a href="%1$s">%2$s</a></h2>',
			esc_url( Organizations::edit_url( $organization->get_id() ) ),
			esc_html( $organization->get_name() )
		);
		printf(
			'<p class="woap-review__meta">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: a date. */
					__( 'Registered %s', 'woo-organization-accounts-pro' ),
					$this->registered_on( $organization )
				)
			)
		);
		echo '</div>';

		echo '<div class="woap-review__body">';
		$this->render_applicant( $applicant );
		$this->render_account( $organization );
		echo '</div>';

		$this->render_decision( $organization, $applicant );

		echo '</div>';
	}

	/**
	 * The person who registered, and who the decision is about.
	 *
	 * @param array $applicant Name and address, or an empty array.
	 * @return void
	 */
	private function render_applicant( array $applicant ) {
		echo '<div class="woap-review__panel">';
		printf( '<h3>%s</h3>', esc_html__( 'Customer', 'woo-organization-accounts-pro' ) );

		if ( empty( $applicant ) ) {
			printf(
				'<p class="woap-review__none">%s</p>',
				esc_html__( 'Nobody is on this account. It was created without a member — most likely imported.', 'woo-organization-accounts-pro' )
			);
			echo '</div>';

			return;
		}

		echo '<dl class="woap-review__facts">';
		$this->fact( __( 'Name', 'woo-organization-accounts-pro' ), $applicant['name'] );
		$this->fact(
			__( 'Email address', 'woo-organization-accounts-pro' ),
			$applicant['email'],
			$applicant['email'] ? 'mailto:' . $applicant['email'] : ''
		);
		echo '</dl>';

		if ( '' !== $applicant['edit_url'] ) {
			printf(
				'<p><a href="%1$s">%2$s</a></p>',
				esc_url( $applicant['edit_url'] ),
				esc_html__( 'View the user account', 'woo-organization-accounts-pro' )
			);
		}

		echo '</div>';
	}

	/**
	 * What the account says about itself.
	 *
	 * The billing address is the one every order will be invoiced to, and the tax ID is the
	 * one regulated identifier the shop holds — between them they are what a review is
	 * actually checking.
	 *
	 * @param Organization $organization The registration.
	 * @return void
	 */
	private function render_account( Organization $organization ) {
		echo '<div class="woap-review__panel">';
		printf( '<h3>%s</h3>', esc_html( Labels::organization() ) );

		echo '<dl class="woap-review__facts">';
		$this->fact(
			sprintf(
				/* translators: %s: the singular organization noun for the site's mode, for example "Company". */
				__( '%s name', 'woo-organization-accounts-pro' ),
				Labels::organization()
			),
			$organization->get_name()
		);
		$this->fact(
			__( 'VAT number, tax ID or registration number', 'woo-organization-accounts-pro' ),
			(string) $organization->get( 'tax_id' )
		);
		echo '</dl>';

		printf( '<h4>%s</h4>', esc_html__( 'Billing address', 'woo-organization-accounts-pro' ) );

		$address = $organization->get_formatted_billing_address();

		if ( '' === trim( wp_strip_all_tags( $address ) ) ) {
			printf(
				'<p class="woap-review__none">%s</p>',
				esc_html__( 'No billing address. Orders cannot be invoiced until one is added.', 'woo-organization-accounts-pro' )
			);
		} else {
			printf( '<address class="woap-review__address">%s</address>', wp_kses_post( $address ) );
		}

		$phone = (string) $organization->get( 'billing_phone' );

		if ( '' !== $phone ) {
			printf( '<p class="woap-review__phone">%s</p>', esc_html( $phone ) );
		}

		echo '</div>';
	}

	/**
	 * The two buttons, and a sentence saying what each will do.
	 *
	 * Naming the consequence is the point of the screen. Both actions send mail, and which
	 * mail depends on which button — that is not something to leave a reviewer to remember
	 * from the settings screen.
	 *
	 * @param Organization $organization The registration.
	 * @param array        $applicant    Who will be told.
	 * @return void
	 */
	private function render_decision( Organization $organization, array $applicant ) {
		echo '<div class="woap-review__decision">';

		printf(
			'<p class="woap-review__consequence">%s</p>',
			esc_html(
				'' !== ( $applicant['email'] ?? '' )
					? sprintf(
						/* translators: 1: an email address. */
						__( 'Approving activates the customer account and lets everybody on it place orders. %1$s is emailed either way — an approval, or a note that the account was not opened.', 'woo-organization-accounts-pro' ),
						$applicant['email']
					)
					: __( 'Approving activates the customer account and lets everybody on it place orders.', 'woo-organization-accounts-pro' )
			)
		);

		printf(
			'<a href="%1$s" class="button button-primary">%2$s</a> ',
			esc_url( Organizations::status_url( $organization->get_id(), Organization::STATUS_ACTIVE, self::PAGE_SLUG ) ),
			esc_html__( 'Approve', 'woo-organization-accounts-pro' )
		);

		printf(
			'<a href="%1$s" class="button">%2$s</a> ',
			esc_url( Organizations::status_url( $organization->get_id(), Organization::STATUS_REJECTED, self::PAGE_SLUG ) ),
			esc_html__( 'Reject', 'woo-organization-accounts-pro' )
		);

		printf(
			'<a href="%1$s" class="button button-link">%2$s</a>',
			esc_url( Organizations::edit_url( $organization->get_id() ) ),
			esc_html__( 'Review the full record', 'woo-organization-accounts-pro' )
		);

		echo '</div>';
	}

	/**
	 * One labelled fact.
	 *
	 * @param string $label What it is.
	 * @param string $value What it says, or an empty string.
	 * @param string $link  Optional href to wrap the value in.
	 * @return void
	 */
	private function fact( $label, $value, $link = '' ) {
		printf( '<dt>%s</dt>', esc_html( $label ) );

		if ( '' === trim( (string) $value ) ) {
			printf( '<dd class="woap-review__none">%s</dd>', esc_html__( 'Not given', 'woo-organization-accounts-pro' ) );

			return;
		}

		printf(
			'<dd>%s</dd>',
			'' !== $link
				? sprintf( '<a href="%1$s">%2$s</a>', esc_url( $link ), esc_html( $value ) )
				: esc_html( $value )
		);
	}

	/**
	 * Who registered the account.
	 *
	 * The organization's admin, and the oldest one where a registration somehow has several
	 * — the account that registered is the one that has been there longest, which is the
	 * order `MemberRepository::for_organization()` already returns.
	 *
	 * @param Organization $organization The registration.
	 * @return array Name, email and an edit link, or an empty array when there is nobody.
	 */
	private function applicant( Organization $organization ) {
		$members = MemberRepository::for_organization(
			$organization->get_id(),
			array( 'role' => Member::ROLE_ADMIN )
		);

		foreach ( $members as $member ) {
			$user = get_user_by( 'id', $member->get_user_id() );

			if ( $user instanceof \WP_User ) {
				return array(
					'name'     => (string) $user->display_name,
					'email'    => (string) $user->user_email,
					'edit_url' => (string) get_edit_user_link( $user->ID ),
				);
			}
		}

		return array();
	}

	/**
	 * When the account registered, in the site's own format.
	 *
	 * @param Organization $organization The registration.
	 * @return string A date, or an empty string.
	 */
	private function registered_on( Organization $organization ) {
		$created = (string) $organization->get( 'date_created' );

		if ( '' === $created ) {
			return '';
		}

		return wp_date( (string) get_option( 'date_format' ), strtotime( $created ) );
	}
}
