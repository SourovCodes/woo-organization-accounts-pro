<?php
/**
 * Managing the people on an organization's account, from wp-admin.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Admin;

use WooOrgAccounts\Data\Invitation;
use WooOrgAccounts\Data\InvitationRepository;
use WooOrgAccounts\Data\LocationRepository;
use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Data\MemberRepository;
use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Data\OrganizationRepository;
use WooOrgAccounts\Labels;
use WooOrgAccounts\Members\Invitations;
use WooOrgAccounts\Membership\Members as MemberService;
use WooOrgAccounts\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * The list of everybody, and the screen where one person is changed.
 *
 * Everything here was already possible over REST and impossible in wp-admin, which is the
 * gap this closes: a shop's own dashboard could not do what the shop's own app could.
 *
 * **A member is edited on a screen of their own**, reached by `?woap_member=<id>`, never on a
 * form folded into the list. The account screens learned this the expensive way — that list
 * was one `<details>` per person, each holding a role, a status, seven permission checkboxes
 * and a checkbox per location, so an account with fifty employees shipped fifty forms to
 * answer *who works here*. The member ID posts back with the form, so a rejected save returns
 * to the person it was about rather than to a blank one.
 *
 * **Adding somebody asks which of two acts it is**, exactly as the REST route does, because
 * they are not variants of one thing. An invitation issues a one-time link and the person
 * sets their own password, so it cannot carry permissions — there is no membership row for
 * them to land on until it is accepted. Creating the account makes it immediately with a
 * random password nobody holds, and sends no mail at all.
 *
 * Every write goes through `Membership\Members`, which is also what the REST routes and the
 * account screens call. That is the point of the service: the last-admin guard, the
 * capability diff against the role *being saved*, and the rule that shop staff are never
 * demoted out of wp-admin are each written once.
 */
class Members {

	/**
	 * Menu slug of the screen.
	 */
	const PAGE_SLUG = 'woo-organization-accounts-members';

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
		add_action( 'admin_post_woap_admin_member_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_woap_admin_member_add', array( $this, 'handle_add' ) );
		add_action( 'admin_post_woap_admin_member_remove', array( $this, 'handle_remove' ) );
	}

	/**
	 * Add the screen under the plugin's menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			Menu::PAGE_SLUG,
			Labels::members(),
			Labels::members(),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Load the screen's assets.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'woap-admin', WOAP_PLUGIN_URL . 'assets/css/admin.css', array(), WOAP_VERSION );
		wp_enqueue_script( 'woap-admin', WOAP_PLUGIN_URL . 'assets/js/admin.js', array(), WOAP_VERSION, true );
	}

	/**
	 * The URL of the list.
	 *
	 * @param int $organization_id Optional organization to filter to.
	 * @return string URL.
	 */
	public static function list_url( $organization_id = 0 ) {
		$args = array( 'page' => self::PAGE_SLUG );

		if ( absint( $organization_id ) > 0 ) {
			$args['organization_id'] = absint( $organization_id );
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * The URL of one member's own screen.
	 *
	 * @param int $member_id Membership ID.
	 * @return string URL.
	 */
	public static function edit_url( $member_id ) {
		return add_query_arg(
			array(
				'page'        => self::PAGE_SLUG,
				'woap_member' => absint( $member_id ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * The URL of the add-somebody screen.
	 *
	 * @param int $organization_id Organization to add them to.
	 * @return string URL.
	 */
	public static function add_url( $organization_id ) {
		return add_query_arg(
			array(
				'page'            => self::PAGE_SLUG,
				'woap_member'     => 'new',
				'organization_id' => absint( $organization_id ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * A nonced URL that takes somebody off an account.
	 *
	 * @param int $member_id Membership ID.
	 * @return string URL.
	 */
	public static function remove_url( $member_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'    => 'woap_admin_member_remove',
					'member_id' => absint( $member_id ),
				),
				admin_url( 'admin-post.php' )
			),
			'woap_admin_member_remove_' . absint( $member_id )
		);
	}

	/**
	 * Render the list, one member, or the add form.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Choosing which read-only screen to show.
		$requested = isset( $_GET['woap_member'] ) ? sanitize_text_field( wp_unslash( $_GET['woap_member'] ) ) : '';

		if ( 'new' === $requested ) {
			$this->render_add();

			return;
		}

		if ( absint( $requested ) > 0 ) {
			$this->render_member( absint( $requested ) );

			return;
		}

		$this->render_list();
	}

	/**
	 * Everybody, across every account.
	 *
	 * @return void
	 */
	private function render_list() {
		$table = new MembersListTable();
		$table->prepare_items();

		$organization_id = MembersListTable::current_organization();

		echo '<div class="wrap woap-members">';
		printf( '<h1 class="wp-heading-inline">%s</h1>', esc_html( Labels::members() ) );

		/*
		 * Adding somebody needs an account to add them to, so the button only appears once
		 * the list is filtered to one. Offering it unfiltered would lead to a form whose
		 * first question is the one the filter already answers.
		 */
		if ( $organization_id > 0 ) {
			printf(
				'<a href="%1$s" class="page-title-action">%2$s</a>',
				esc_url( self::add_url( $organization_id ) ),
				esc_html__( 'Add somebody', 'woo-organization-accounts-pro' )
			);
		}

		echo '<hr class="wp-header-end">';

		Notices::render();

		$table->views();

		printf( '<form method="get" action="%s">', esc_url( admin_url( 'admin.php' ) ) );
		printf( '<input type="hidden" name="page" value="%s">', esc_attr( self::PAGE_SLUG ) );

		$role = MembersListTable::current_role();

		if ( '' !== $role ) {
			printf( '<input type="hidden" name="role" value="%s">', esc_attr( $role ) );
		}

		$table->search_box( __( 'Search', 'woo-organization-accounts-pro' ), 'woap-member-search' );
		$table->display();

		echo '</form>';
		echo '</div>';
	}

	/**
	 * One person's own screen.
	 *
	 * @param int $member_id Membership ID.
	 * @return void
	 */
	private function render_member( $member_id ) {
		$member = MemberRepository::find( $member_id );

		if ( ! $member instanceof Member ) {
			$this->render_gone();

			return;
		}

		$organization = OrganizationRepository::find( $member->get_organization_id() );
		$user         = get_user_by( 'id', $member->get_user_id() );

		if ( ! $organization instanceof Organization ) {
			$this->render_gone();

			return;
		}

		list( $errors, $submitted ) = Notices::consume();

		echo '<div class="wrap woap-member-detail">';

		printf(
			'<h1>%s</h1>',
			esc_html(
				$user instanceof \WP_User
					? $user->display_name
					: __( '(deleted account)', 'woo-organization-accounts-pro' )
			)
		);

		$this->render_breadcrumb( $organization );

		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		echo '<input type="hidden" name="action" value="woap_admin_member_save">';
		printf( '<input type="hidden" name="woap_member_id" value="%d">', (int) $member->get_id() );
		wp_nonce_field( 'woap_admin_member_save_' . $member->get_id() );

		$this->render_identity( $user, $errors, $submitted );
		$this->render_membership( $member, $organization, $errors, $submitted );

		submit_button( __( 'Save changes', 'woo-organization-accounts-pro' ) );

		echo '</form>';
		echo '</div>';
	}

	/**
	 * The add-somebody screen.
	 *
	 * @return void
	 */
	private function render_add() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Choosing which read-only screen to show.
		$organization_id = isset( $_GET['organization_id'] ) ? absint( wp_unslash( $_GET['organization_id'] ) ) : 0;
		$organization    = OrganizationRepository::find( $organization_id );

		if ( ! $organization instanceof Organization ) {
			$this->render_gone();

			return;
		}

		list( $errors, $submitted ) = Notices::consume();

		echo '<div class="wrap woap-member-detail">';
		printf(
			'<h1>%s</h1>',
			esc_html(
				sprintf(
					/* translators: %s: the singular member noun for the site's mode, for example "Employee". */
					__( 'Add an %s', 'woo-organization-accounts-pro' ),
					strtolower( Labels::member() )
				)
			)
		);

		$this->render_breadcrumb( $organization );

		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		echo '<input type="hidden" name="action" value="woap_admin_member_add">';
		printf( '<input type="hidden" name="woap_organization_id" value="%d">', (int) $organization->get_id() );
		wp_nonce_field( 'woap_admin_member_add_' . $organization->get_id() );

		echo '<table class="form-table" role="presentation"><tbody>';
		$this->text_row( 'woap_email', __( 'Email address', 'woo-organization-accounts-pro' ), $submitted['woap_email'] ?? '', $errors );
		$this->text_row( 'woap_first_name', __( 'First name', 'woo-organization-accounts-pro' ), $submitted['woap_first_name'] ?? '', $errors );
		$this->text_row( 'woap_last_name', __( 'Last name', 'woo-organization-accounts-pro' ), $submitted['woap_last_name'] ?? '', $errors );
		$this->role_row( (string) ( $submitted['woap_role'] ?? Member::ROLE_MEMBER ), $errors );
		echo '</tbody></table>';

		$this->render_method_choice( (string) ( $submitted['woap_method'] ?? 'invite' ) );

		submit_button( __( 'Add them', 'woo-organization-accounts-pro' ) );

		echo '</form>';
		echo '</div>';
	}

	/**
	 * The invite-or-create question.
	 *
	 * @param string $chosen Which is selected.
	 * @return void
	 */
	private function render_method_choice( $chosen ) {
		$options = array(
			'invite' => array(
				__( 'Send them an invitation', 'woo-organization-accounts-pro' ),
				__( 'They receive a one-time link, choose their own password, and join when they accept. Nothing exists on the account until then, so permissions and delivery access are set afterwards.', 'woo-organization-accounts-pro' ),
			),
			'create' => array(
				__( 'Create the account now', 'woo-organization-accounts-pro' ),
				__( 'The account exists immediately, with a password nobody holds — they set one through the shop\'s lost-password form. No email is sent.', 'woo-organization-accounts-pro' ),
			),
		);

		echo '<div class="woap-choice">';
		printf( '<h2>%s</h2>', esc_html__( 'How should they be added?', 'woo-organization-accounts-pro' ) );

		foreach ( $options as $value => $option ) {
			printf(
				'<p><label><input type="radio" name="woap_method" value="%1$s"%2$s> <strong>%3$s</strong></label><br><span class="description">%4$s</span></p>',
				esc_attr( $value ),
				checked( $chosen, $value, false ),
				esc_html( $option[0] ),
				esc_html( $option[1] )
			);
		}

		echo '</div>';
	}

	/**
	 * The name and address, which live on the WordPress account rather than here.
	 *
	 * @param \WP_User|false $user      The account.
	 * @param \WP_Error|null $errors    Errors from a rejected save.
	 * @param array          $submitted What that save tried to store.
	 * @return void
	 */
	private function render_identity( $user, $errors, array $submitted ) {
		printf( '<h2>%s</h2>', esc_html__( 'Their details', 'woo-organization-accounts-pro' ) );

		if ( ! $user instanceof \WP_User ) {
			printf(
				'<p>%s</p>',
				esc_html__( 'The WordPress account behind this membership has been deleted. The membership can be removed, but nothing about the person can be edited.', 'woo-organization-accounts-pro' )
			);

			return;
		}

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'These are kept on the WordPress account, so changing them here changes them everywhere on the shop.', 'woo-organization-accounts-pro' )
		);

		echo '<table class="form-table" role="presentation"><tbody>';
		$this->text_row( 'woap_first_name', __( 'First name', 'woo-organization-accounts-pro' ), $submitted['woap_first_name'] ?? $user->first_name, $errors );
		$this->text_row( 'woap_last_name', __( 'Last name', 'woo-organization-accounts-pro' ), $submitted['woap_last_name'] ?? $user->last_name, $errors );
		$this->text_row( 'woap_email', __( 'Email address', 'woo-organization-accounts-pro' ), $submitted['woap_email'] ?? $user->user_email, $errors );
		echo '</tbody></table>';
	}

	/**
	 * The role, status, permissions and delivery access.
	 *
	 * @param Member         $member       The membership.
	 * @param Organization   $organization The account it belongs to.
	 * @param \WP_Error|null $errors       Errors from a rejected save.
	 * @param array          $submitted    What that save tried to store.
	 * @return void
	 */
	private function render_membership( Member $member, Organization $organization, $errors, array $submitted ) {
		printf( '<h2>%s</h2>', esc_html__( 'On this account', 'woo-organization-accounts-pro' ) );

		echo '<table class="form-table" role="presentation"><tbody>';
		$this->role_row( (string) ( $submitted['woap_role'] ?? $member->get_role() ), $errors );
		$this->status_row( (string) ( $submitted['woap_status'] ?? $member->get( 'status' ) ) );
		echo '</tbody></table>';

		$this->render_permissions( $member, $submitted );
		$this->render_location_access( $member, $organization, $errors, $submitted );
	}

	/**
	 * The permissions block: follow the role, or choose them one by one.
	 *
	 * The radio is what the server reads. Overrides are stored as a diff against the role's
	 * defaults, so "everything the role allows" and "these seven, which happen to match the
	 * role" are different answers with different futures — the first follows the role if it
	 * ever changes and the second does not — and a list of checkboxes cannot say which one
	 * it means.
	 *
	 * @param Member $member    The membership.
	 * @param array  $submitted What a rejected save tried to store.
	 * @return void
	 */
	private function render_permissions( Member $member, array $submitted ) {
		$stored = $member->get_capabilities();
		$scope  = (string) ( $submitted['woap_permissions_scope'] ?? ( empty( $stored ) ? 'role' : 'custom' ) );

		$resolved = array_merge( Roles::role_capabilities( $member->get_role() ), $stored );
		$granted  = isset( $submitted['woap_capabilities'] )
			? (array) $submitted['woap_capabilities']
			: array_keys( array_filter( $resolved ) );

		printf( '<h2>%s</h2>', esc_html__( 'Permissions', 'woo-organization-accounts-pro' ) );

		echo '<div class="woap-choice" data-woap-choice="permissions">';

		printf(
			'<p><label><input type="radio" name="woap_permissions_scope" value="role"%1$s> %2$s</label></p>',
			checked( $scope, 'role', false ),
			esc_html__( 'Whatever the role allows', 'woo-organization-accounts-pro' )
		);

		printf(
			'<p><label><input type="radio" name="woap_permissions_scope" value="custom"%1$s> %2$s</label></p>',
			checked( $scope, 'custom', false ),
			esc_html__( 'Choose them one by one', 'woo-organization-accounts-pro' )
		);

		echo '<div class="woap-choice__detail" data-woap-choice-detail="custom">';

		foreach ( Roles::labels() as $capability => $label ) {
			printf(
				'<p><label><input type="checkbox" name="woap_capabilities[]" value="%1$s"%2$s> %3$s</label></p>',
				esc_attr( $capability ),
				checked( in_array( $capability, $granted, true ), true, false ),
				esc_html( $label )
			);
		}

		echo '</div></div>';
	}

	/**
	 * The delivery-access block.
	 *
	 * @param Member         $member       The membership.
	 * @param Organization   $organization The account it belongs to.
	 * @param \WP_Error|null $errors       Errors from a rejected save.
	 * @param array          $submitted    What that save tried to store.
	 * @return void
	 */
	private function render_location_access( Member $member, Organization $organization, $errors, array $submitted ) {
		$locations = LocationRepository::for_organization( $organization->get_id() );
		$stored    = MemberRepository::location_ids( $member->get_id() );
		$scope     = (string) ( $submitted['woap_location_scope'] ?? ( empty( $stored ) ? 'all' : 'selected' ) );
		$chosen    = array_map( 'absint', (array) ( $submitted['woap_location_access'] ?? $stored ) );

		printf( '<h2>%s</h2>', esc_html( Labels::locations() ) );

		if ( empty( $locations ) ) {
			printf(
				'<p>%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: the plural location noun for the site's mode, for example "Branches". */
						__( 'This account has no %s yet, so there is nothing to restrict anybody to.', 'woo-organization-accounts-pro' ),
						strtolower( Labels::locations() )
					)
				)
			);

			return;
		}

		$this->render_error( 'woap_location_access', $errors );

		echo '<div class="woap-choice" data-woap-choice="locations">';

		printf(
			'<p><label><input type="radio" name="woap_location_scope" value="all"%1$s> %2$s</label></p>',
			checked( $scope, 'all', false ),
			esc_html(
				sprintf(
					/* translators: %s: the plural location noun for the site's mode, for example "Branches". */
					__( 'May ship to all %s', 'woo-organization-accounts-pro' ),
					strtolower( Labels::locations() )
				)
			)
		);

		printf(
			'<p><label><input type="radio" name="woap_location_scope" value="selected"%1$s> %2$s</label></p>',
			checked( $scope, 'selected', false ),
			esc_html__( 'Only the ones ticked below', 'woo-organization-accounts-pro' )
		);

		echo '<div class="woap-choice__detail" data-woap-choice-detail="selected">';

		foreach ( $locations as $location ) {
			printf(
				'<p><label><input type="checkbox" name="woap_location_access[]" value="%1$d"%2$s> %3$s</label></p>',
				(int) $location->get_id(),
				checked( in_array( $location->get_id(), $chosen, true ), true, false ),
				esc_html( $location->get_name() )
			);
		}

		echo '</div></div>';
	}

	/**
	 * The link back to the account this person is on.
	 *
	 * @param Organization $organization The account.
	 * @return void
	 */
	private function render_breadcrumb( Organization $organization ) {
		printf(
			'<p><a href="%1$s">%2$s</a> &nbsp;|&nbsp; <a href="%3$s">%4$s</a></p>',
			esc_url( Organizations::edit_url( $organization->get_id() ) ),
			esc_html( $organization->get_name() ),
			esc_url( self::list_url( $organization->get_id() ) ),
			esc_html(
				sprintf(
					/* translators: %s: the plural member noun for the site's mode, for example "Employees". */
					__( 'All %s on this account', 'woo-organization-accounts-pro' ),
					strtolower( Labels::members() )
				)
			)
		);
	}

	/**
	 * The role control.
	 *
	 * @param string         $chosen Which role is selected.
	 * @param \WP_Error|null $errors Errors from a rejected save.
	 * @return void
	 */
	private function role_row( $chosen, $errors ) {
		echo '<tr><th scope="row"><label for="woap_role">' . esc_html__( 'Role', 'woo-organization-accounts-pro' ) . '</label></th><td>';
		echo '<select name="woap_role" id="woap_role">';

		foreach ( Member::roles() as $role => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $role ),
				selected( $chosen, $role, false ),
				esc_html( $label )
			);
		}

		echo '</select>';
		$this->render_error( 'woap_role', $errors );
		echo '</td></tr>';
	}

	/**
	 * The status control.
	 *
	 * @param string $chosen Which status is selected.
	 * @return void
	 */
	private function status_row( $chosen ) {
		echo '<tr><th scope="row"><label for="woap_status">' . esc_html__( 'Status', 'woo-organization-accounts-pro' ) . '</label></th><td>';
		echo '<select name="woap_status" id="woap_status">';

		$statuses = array(
			Member::STATUS_ACTIVE   => __( 'Active', 'woo-organization-accounts-pro' ),
			Member::STATUS_INACTIVE => __( 'Inactive', 'woo-organization-accounts-pro' ),
		);

		foreach ( $statuses as $status => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $status ),
				selected( $chosen, $status, false ),
				esc_html( $label )
			);
		}

		echo '</select>';
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'An inactive member keeps their login and their order history, and cannot buy on this account.', 'woo-organization-accounts-pro' )
		);
		echo '</td></tr>';
	}

	/**
	 * One text field in a form table.
	 *
	 * @param string         $name   Field name.
	 * @param string         $label  Its label.
	 * @param string         $value  Its current value.
	 * @param \WP_Error|null $errors Errors from a rejected save.
	 * @return void
	 */
	private function text_row( $name, $label, $value, $errors ) {
		$invalid = ( $errors instanceof \WP_Error ) && ! empty( $errors->get_error_message( $name ) );

		printf(
			'<tr class="%1$s"><th scope="row"><label for="%2$s">%3$s</label></th><td>',
			$invalid ? 'woap-row--invalid' : '',
			esc_attr( $name ),
			esc_html( $label )
		);

		printf(
			'<input type="text" class="regular-text" name="%1$s" id="%1$s" value="%2$s">',
			esc_attr( $name ),
			esc_attr( (string) $value )
		);

		$this->render_error( $name, $errors );

		echo '</td></tr>';
	}

	/**
	 * The message for one field, if it was rejected.
	 *
	 * @param string         $name   Field name.
	 * @param \WP_Error|null $errors Errors from a rejected save.
	 * @return void
	 */
	private function render_error( $name, $errors ) {
		if ( ! $errors instanceof \WP_Error ) {
			return;
		}

		$message = $errors->get_error_message( $name );

		if ( '' === $message ) {
			return;
		}

		printf( '<p class="woap-field-error">%s</p>', wp_kses_post( $message ) );
	}

	/**
	 * The record is not there any more.
	 *
	 * @return void
	 */
	private function render_gone() {
		echo '<div class="wrap"><p>';
		esc_html_e( 'That record no longer exists.', 'woo-organization-accounts-pro' );
		echo '</p></div>';
	}

	/**
	 * Save an edit to one person.
	 *
	 * @return void
	 */
	public function handle_save() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer() below, which needs the ID to build the action.
		$member_id = isset( $_POST['woap_member_id'] ) ? absint( wp_unslash( $_POST['woap_member_id'] ) ) : 0;

		check_admin_referer( 'woap_admin_member_save_' . $member_id );
		self::require_capability();

		$member = MemberRepository::find( $member_id );

		if ( ! $member instanceof Member ) {
			Notices::error( __( 'That record no longer exists.', 'woo-organization-accounts-pro' ) );
			$this->go_to( self::list_url() );
		}

		$scope        = ( 'custom' === self::posted( 'woap_permissions_scope' ) ) ? 'custom' : 'role';
		$restricting  = 'selected' === self::posted( 'woap_location_scope' );
		$location_ids = self::posted_ids( 'woap_location_access' );
		$errors       = new \WP_Error();

		/*
		 * An empty access list is the stored form of "every location", so "only the ones I
		 * tick" with nothing ticked would store the opposite of what it says. The question
		 * belongs to this form rather than to the service, because it is a question about
		 * the control the form drew.
		 */
		if ( $restricting && empty( $location_ids ) ) {
			$errors->add(
				'woap_location_access',
				sprintf(
					/* translators: %s: the singular location noun for the site's mode, for example "Branch". */
					__( 'Choose at least one %s, or give access to all of them.', 'woo-organization-accounts-pro' ),
					Labels::location()
				)
			);
		}

		if ( ! $errors->has_errors() ) {
			$saved = MemberService::update(
				$member,
				array(
					'first_name'      => self::posted( 'woap_first_name' ),
					'last_name'       => self::posted( 'woap_last_name' ),
					'email'           => self::posted_email( 'woap_email' ),
					'role'            => self::posted( 'woap_role' ),
					'status'          => self::posted( 'woap_status' ),
					'capabilities'    => 'custom' === $scope
						? self::posted_capabilities()
						: MemberService::ROLE_DEFAULT,
					'location_access' => $restricting ? $location_ids : MemberService::ACCESS_ALL,
				)
			);

			if ( is_wp_error( $saved ) ) {
				$errors = self::prefixed( self::keyed_to_email( $saved ) );
			}
		}

		if ( $errors->has_errors() ) {
			Notices::hold( $errors, self::submission( $scope, $restricting, $location_ids ) );
			$this->go_to( self::edit_url( $member_id ) );
		}

		Notices::success(
			sprintf(
				/* translators: %s: the singular member noun for the site's mode, for example "Employee". */
				__( '%s updated.', 'woo-organization-accounts-pro' ),
				Labels::member()
			)
		);

		$this->go_to( self::edit_url( $member_id ) );
	}

	/**
	 * Invite somebody, or create their account outright.
	 *
	 * @return void
	 */
	public function handle_add() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer() below, which needs the ID to build the action.
		$organization_id = isset( $_POST['woap_organization_id'] ) ? absint( wp_unslash( $_POST['woap_organization_id'] ) ) : 0;

		check_admin_referer( 'woap_admin_member_add_' . $organization_id );
		self::require_capability();

		$organization = OrganizationRepository::find( $organization_id );

		if ( ! $organization instanceof Organization ) {
			Notices::error( __( 'That record no longer exists.', 'woo-organization-accounts-pro' ) );
			$this->go_to( self::list_url() );
		}

		$email  = self::posted_email( 'woap_email' );
		$role   = ( Member::ROLE_ADMIN === self::posted( 'woap_role' ) ) ? Member::ROLE_ADMIN : Member::ROLE_MEMBER;
		$method = ( 'create' === self::posted( 'woap_method' ) ) ? 'create' : 'invite';

		$result = ( 'invite' === $method )
			? Invitations::create( $organization->get_id(), $email, $role, get_current_user_id() )
			: MemberService::add(
				$organization,
				array(
					'email'      => $email,
					'role'       => $role,
					'first_name' => self::posted( 'woap_first_name' ),
					'last_name'  => self::posted( 'woap_last_name' ),
				)
			);

		if ( is_wp_error( $result ) ) {
			Notices::hold(
				self::prefixed( self::keyed_to_email( $result ) ),
				array(
					'woap_email'      => $email,
					'woap_role'       => $role,
					'woap_method'     => $method,
					'woap_first_name' => self::posted( 'woap_first_name' ),
					'woap_last_name'  => self::posted( 'woap_last_name' ),
				)
			);

			$this->go_to( self::add_url( $organization_id ) );
		}

		if ( 'invite' === $method ) {
			Notices::success( __( 'Invitation sent.', 'woo-organization-accounts-pro' ) );
			$this->go_to( Organizations::edit_url( $organization_id ) . '&woap_tab=invitations' );
		}

		Notices::success(
			sprintf(
				/* translators: %s: the singular member noun for the site's mode, for example "Employee". */
				__( '%s added. They set a password through the shop\'s lost-password form; no email has been sent.', 'woo-organization-accounts-pro' ),
				Labels::member()
			)
		);

		$this->go_to( self::list_url( $organization_id ) );
	}

	/**
	 * Take somebody off an account.
	 *
	 * @return void
	 */
	public function handle_remove() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified by check_admin_referer() below, which needs the ID to build the action.
		$member_id = isset( $_GET['member_id'] ) ? absint( wp_unslash( $_GET['member_id'] ) ) : 0;

		check_admin_referer( 'woap_admin_member_remove_' . $member_id );
		self::require_capability();

		$member = MemberRepository::find( $member_id );

		if ( ! $member instanceof Member ) {
			Notices::error( __( 'That record no longer exists.', 'woo-organization-accounts-pro' ) );
			$this->go_to( self::list_url() );
		}

		$organization_id = $member->get_organization_id();
		$removed         = MemberService::remove( $member );

		if ( is_wp_error( $removed ) ) {
			Notices::error( $removed->get_error_message() );
			$this->go_to( self::edit_url( $member_id ) );
		}

		Notices::success(
			sprintf(
				/* translators: %s: the singular member noun for the site's mode, for example "Employee". */
				__( '%s removed. Their login and their orders are kept.', 'woo-organization-accounts-pro' ),
				Labels::member()
			)
		);

		$this->go_to( self::list_url( $organization_id ) );
	}

	/**
	 * What a rejected submission was carrying, so the form can be refilled from it.
	 *
	 * @param string $scope        Which permissions answer was given.
	 * @param bool   $restricting  Whether locations were being restricted.
	 * @param array  $location_ids Which locations were ticked.
	 * @return array The submission.
	 */
	private static function submission( $scope, $restricting, array $location_ids ) {
		return array(
			'woap_first_name'        => self::posted( 'woap_first_name' ),
			'woap_last_name'         => self::posted( 'woap_last_name' ),
			'woap_email'             => self::posted_email( 'woap_email' ),
			'woap_role'              => self::posted( 'woap_role' ),
			'woap_status'            => self::posted( 'woap_status' ),
			'woap_permissions_scope' => $scope,
			'woap_location_scope'    => $restricting ? 'selected' : 'all',
			'woap_capabilities'      => self::posted_list( 'woap_capabilities' ),
			'woap_location_access'   => $location_ids,
		);
	}

	/**
	 * What a permissions form said should be true of this member.
	 *
	 * An absolute map, every capability named, because a group of checkboxes says as much
	 * by what it leaves unticked. Reducing it to the overrides worth storing happens in the
	 * service, against the role *being saved*.
	 *
	 * @return array Map of capability to boolean.
	 */
	private static function posted_capabilities() {
		$granted = self::posted_list( 'woap_capabilities' );
		$wanted  = array();

		foreach ( Roles::capabilities() as $capability ) {
			$wanted[ $capability ] = in_array( $capability, $granted, true );
		}

		return $wanted;
	}

	/**
	 * Re-key a service refusal the way this form's fields are named.
	 *
	 * @param \WP_Error $error The refusal.
	 * @return \WP_Error The same messages, keyed by field name.
	 */
	private static function prefixed( \WP_Error $error ) {
		$prefixed = new \WP_Error();

		foreach ( $error->get_error_codes() as $code ) {
			$code = (string) $code;
			$key  = 0 === strpos( $code, 'woap_' ) ? $code : 'woap_' . $code;

			$prefixed->add( $key, $error->get_error_message( $code ) );
		}

		return $prefixed;
	}

	/**
	 * Point a whole-refusal error at the address field it is about.
	 *
	 * Both "already a member" and "that address has an account" are answers about the one
	 * thing the add form asks for, and a message with no field to sit under is a banner over
	 * a form with four inputs.
	 *
	 * @param \WP_Error $error The refusal.
	 * @return \WP_Error The refusal, keyed by field where it belongs to one.
	 */
	private static function keyed_to_email( \WP_Error $error ) {
		$whole = array(
			MemberService::ERROR_ALREADY_MEMBER,
			MemberService::ERROR_EMAIL_TAKEN,
			'woap_already_member',
			'woap_invalid_email',
		);

		$code = (string) $error->get_error_code();

		if ( ! in_array( $code, $whole, true ) ) {
			return $error;
		}

		return new \WP_Error( 'email', $error->get_error_message( $code ) );
	}

	/**
	 * Read a posted text field.
	 *
	 * @param string $key Field name.
	 * @return string Sanitised value.
	 */
	private static function posted( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() ran before this is reached.
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/**
	 * Read a posted email field.
	 *
	 * @param string $key Field name.
	 * @return string Sanitised value.
	 */
	private static function posted_email( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- As above.
		return isset( $_POST[ $key ] ) ? sanitize_email( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/**
	 * Read a posted group of checkboxes.
	 *
	 * @param string $key Field name.
	 * @return string[] Sanitised values.
	 */
	private static function posted_list( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- As above.
		if ( ! isset( $_POST[ $key ] ) ) {
			return array();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- As above.
		return array_map( 'sanitize_key', (array) wp_unslash( $_POST[ $key ] ) );
	}

	/**
	 * Read a posted group of ID checkboxes.
	 *
	 * @param string $key Field name.
	 * @return int[] The IDs.
	 */
	private static function posted_ids( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- As above.
		if ( ! isset( $_POST[ $key ] ) ) {
			return array();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- As above.
		return array_values( array_filter( array_map( 'absint', (array) wp_unslash( $_POST[ $key ] ) ) ) );
	}

	/**
	 * Refuse anybody without the capability.
	 *
	 * @return void
	 */
	private static function require_capability() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You are not allowed to do that.', 'woo-organization-accounts-pro' ),
				'',
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Finish a handler by going somewhere.
	 *
	 * @param string $url Where to go.
	 * @return void
	 */
	private function go_to( $url ) {
		wp_safe_redirect( $url );
		exit;
	}
}
