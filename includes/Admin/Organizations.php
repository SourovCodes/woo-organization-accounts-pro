<?php
/**
 * Organizations admin screen.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Admin;

use WooOrgAccounts\Checkout\OrderMeta;
use WooOrgAccounts\Data\InvitationRepository;
use WooOrgAccounts\Data\LocationRepository;
use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Data\MemberRepository;
use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Data\OrganizationRepository;
use WooOrgAccounts\Frontend\AddressFields;
use WooOrgAccounts\Frontend\MyAccount;
use WooOrgAccounts\Labels;

defined( 'ABSPATH' ) || exit;

/**
 * The shop administrator's view of every organization on the site.
 *
 * A list with status filters, and a detail screen per organization showing its
 * members, locations, invitations and orders. Approving, suspending and rejecting
 * happen here — an organization can register itself, but only the shop can let it buy.
 */
class Organizations {

	/**
	 * Menu slug of the screen.
	 */
	const PAGE_SLUG = 'woo-organization-accounts-list';

	/**
	 * Capability required to use it.
	 */
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * Transient prefix messages are parked under between a save and its redirect.
	 *
	 * Per user, and short-lived: this is a handover between two requests, not storage.
	 * `admin-post.php` produces no output, so a rejected save has nowhere to say so
	 * until the screen renders again.
	 */
	const NOTICE_TRANSIENT = 'woap_admin_notices_';

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_woap_admin_set_status', array( $this, 'handle_set_status' ) );
		add_action( 'admin_post_woap_admin_delete', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_woap_admin_save', array( $this, 'handle_save' ) );
	}

	/**
	 * Add the screen under the WooCommerce menu.
	 *
	 * The bulk actions are handled on the screen's own `load-` hook rather than through
	 * `admin-post.php`, because `WP_List_Table` owns the two fields such a round trip
	 * would need. Its bulk select is named `action`, which is the field `admin-post.php`
	 * routes on, and it prints its own `bulk-organizations` nonce — both after anything
	 * the form printed first, so PHP kept the table's copy of each and the request
	 * arrived asking for `admin_post_approve` with a nonce for something else. Nothing
	 * was hooked to that, so every bulk approve, suspend and reject did nothing at all.
	 * Handling it here is what wp-admin's own list screens do, and it leaves one field
	 * named `action` and one nonce.
	 *
	 * @return void
	 */
	public function register_menu() {
		/*
		 * The same slug as the top-level menu, which is what names the duplicate first item
		 * WordPress creates for a parent — "All Companies" rather than the menu's own title
		 * repeated. Registering a different slug here would leave the parent pointing at a
		 * page nothing renders.
		 */
		$hook = add_submenu_page(
			Menu::PAGE_SLUG,
			Labels::organizations(),
			sprintf(
				/* translators: %s: the plural organization noun for the site's mode, for example "Companies". */
				__( 'All %s', 'woo-organization-accounts-pro' ),
				Labels::organizations()
			),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);

		if ( is_string( $hook ) && '' !== $hook ) {
			add_action( 'load-' . $hook, array( $this, 'handle_bulk_status' ) );
		}
	}

	/**
	 * Load the stylesheet the status pills and detail columns need.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'woap-admin', WOAP_PLUGIN_URL . 'assets/css/admin.css', array(), WOAP_VERSION );

		/*
		 * The same country and state behaviour the customer gets. WooCommerce registers
		 * these on the frontend only, so AddressFields registers them here against
		 * WooCommerce's own files; both scripts bail out quietly if their parameters ever
		 * change shape, leaving the server-rendered control in place.
		 */
		AddressFields::enqueue();
	}

	/**
	 * The URL of the list, keeping whichever status filter and search it is showing.
	 *
	 * @return string URL.
	 */
	public static function list_url() {
		$args = array( 'page' => self::PAGE_SLUG );

		$status = self::filter_value( 'status' );
		$search = self::filter_value( 's' );

		if ( '' !== $status ) {
			$args['status'] = sanitize_key( $status );
		}

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Read one of the list's filters.
	 *
	 * Only the URL, because only the URL is where the list's filters live — see the GET
	 * form in `render_list()`. Read by name rather than through `$_REQUEST`, which the
	 * plugin never touches.
	 *
	 * @param string $key Filter name.
	 * @return string Sanitised value, or an empty string.
	 */
	private static function filter_value( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Choosing which read-only view of the list to show.
		return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
	}

	/**
	 * The URL of one organization's detail screen.
	 *
	 * @param int $organization_id Organization ID.
	 * @return string URL.
	 */
	public static function edit_url( $organization_id ) {
		return add_query_arg(
			array(
				'page'            => self::PAGE_SLUG,
				'organization_id' => absint( $organization_id ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * A nonced URL that moves an organization to a status.
	 *
	 * @param int    $organization_id Organization ID.
	 * @param string $status          Target status.
	 * @param string $return_to       Optional screen slug to come back to.
	 * @return string URL.
	 */
	public static function status_url( $organization_id, $status, $return_to = '' ) {
		$args = array(
			'action'          => 'woap_admin_set_status',
			'organization_id' => absint( $organization_id ),
			'status'          => $status,
		);

		if ( '' !== $return_to ) {
			$args['woap_return'] = sanitize_key( $return_to );
		}

		return wp_nonce_url(
			add_query_arg( $args, admin_url( 'admin-post.php' ) ),
			'woap_admin_set_status_' . absint( $organization_id )
		);
	}

	/**
	 * A nonced URL that deletes an organization.
	 *
	 * @param int $organization_id Organization ID.
	 * @return string URL.
	 */
	public static function delete_url( $organization_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'          => 'woap_admin_delete',
					'organization_id' => absint( $organization_id ),
				),
				admin_url( 'admin-post.php' )
			),
			'woap_admin_delete_' . absint( $organization_id )
		);
	}

	/**
	 * Render the list or the detail screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Choosing which read-only screen to show.
		$organization_id = isset( $_GET['organization_id'] ) ? absint( wp_unslash( $_GET['organization_id'] ) ) : 0;

		if ( $organization_id > 0 ) {
			$this->render_detail( $organization_id );

			return;
		}

		$this->render_list();
	}

	/**
	 * Render the list of organizations.
	 *
	 * @return void
	 */
	private function render_list() {
		$table = new OrganizationsListTable();
		$table->prepare_items();

		echo '<div class="wrap woap-organizations">';
		printf( '<h1 class="wp-heading-inline">%s</h1>', esc_html( Labels::organizations() ) );

		/*
		 * The only way to reach the import screen. It is registered under this menu and
		 * then taken back out of it, because a permanent menu item for something a shop
		 * does once is clutter on every other day — and this is where somebody who wants
		 * it is already standing.
		 */
		printf(
			'<a href="%1$s" class="page-title-action">%2$s</a>',
			esc_url( Import::url() ),
			esc_html__( 'Import', 'woo-organization-accounts-pro' )
		);

		echo '<hr class="wp-header-end">';

		$table->views();

		/*
		 * A GET form back to this same screen, which is the shape wp-admin's own list
		 * screens use and the only one where the parts compose. Everything that decides
		 * what the list shows — the search term, the status filter, the sort column and
		 * the page number — has to be in the URL, because `WP_List_Table` builds its
		 * paging and sortable headers out of the current URL and reads the search back
		 * from the query string. Submitted by POST, a search returned page one of an
		 * unfiltered list and page two of a search was not a search.
		 *
		 * It carries nothing the table prints for itself: `action` and the nonce are
		 * both its, and a second field of either name would be the one PHP discarded.
		 */
		printf(
			'<form method="get" action="%s">',
			esc_url( admin_url( 'admin.php' ) )
		);
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '">';

		$status = self::filter_value( 'status' );

		if ( '' !== $status ) {
			echo '<input type="hidden" name="status" value="' . esc_attr( sanitize_key( $status ) ) . '">';
		}

		$table->search_box( __( 'Search', 'woo-organization-accounts-pro' ), 'woap-search' );
		$table->display();

		echo '</form>';
		echo '</div>';
	}

	/**
	 * Render one organization's detail screen.
	 *
	 * @param int $organization_id Organization ID.
	 * @return void
	 */
	private function render_detail( $organization_id ) {
		$organization = OrganizationRepository::find( $organization_id );

		if ( null === $organization ) {
			echo '<div class="wrap"><p>';
			esc_html_e( 'That account no longer exists.', 'woo-organization-accounts-pro' );
			echo '</p></div>';

			return;
		}

		$orders = MyAccount::organization_orders( $organization->get_id(), 20, 1 );

		echo '<div class="wrap woap-organization-detail">';

		list( $rejected, $submitted ) = self::render_notices();

		printf(
			'<h1>%1$s <span class="woap-status woap-status--%2$s">%3$s</span></h1>',
			esc_html( $organization->get_name() ),
			esc_attr( $organization->get_status() ),
			esc_html( $organization->get_status_label() )
		);

		printf(
			'<p><a href="%1$s">%2$s</a></p>',
			esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ),
			esc_html(
				sprintf(
					/* translators: %s: the plural organization noun for the site's mode. */
					__( '← Back to all %s', 'woo-organization-accounts-pro' ),
					Labels::organizations()
				)
			)
		);

		$this->render_detail_form( $organization, $rejected, $submitted );
		$this->render_members( $organization );
		$this->render_locations( $organization );
		$this->render_invitations( $organization );
		$this->render_orders( $organization, $orders['orders'] );

		echo '</div>';
	}

	/**
	 * The editable part of the detail screen.
	 *
	 * @param Organization   $organization The organization.
	 * @param \WP_Error|null $rejected     Errors from a rejected save, if any.
	 * @param array          $submitted    What that save tried to store.
	 * @return void
	 */
	private function render_detail_form( Organization $organization, $rejected = null, array $submitted = array() ) {
		$billing = $organization->get_billing_address();

		foreach ( array_keys( $billing ) as $field ) {
			if ( array_key_exists( AddressFields::BILLING . '_' . $field, $submitted ) ) {
				$billing[ $field ] = $submitted[ AddressFields::BILLING . '_' . $field ];
			}
		}

		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		echo '<input type="hidden" name="action" value="woap_admin_save">';
		printf( '<input type="hidden" name="organization_id" value="%d">', (int) $organization->get_id() );
		wp_nonce_field( 'woap_admin_save_' . $organization->get_id() );

		echo '<div class="woap-detail-columns">';

		$details = array(
			'name'   => $organization->get_name(),
			'tax_id' => (string) $organization->get( 'tax_id' ),
			'status' => $organization->get_status(),
		);

		/*
		 * What a rejected save tried to store wins over what is stored, for the same
		 * reason the address does: correcting one field must not mean retyping the rest.
		 */
		foreach ( array_keys( $details ) as $field ) {
			if ( array_key_exists( 'woap_' . $field, $submitted ) ) {
				$details[ $field ] = $submitted[ 'woap_' . $field ];
			}
		}

		/*
		 * No email or phone row: an organization's contact details are its billing
		 * email and billing phone, in the address column beside this one, which is the
		 * pair that reaches an order.
		 */
		echo '<div><h2>' . esc_html__( 'Details', 'woo-organization-accounts-pro' ) . '</h2><table class="form-table"><tbody>';
		self::text_row( 'name', __( 'Name', 'woo-organization-accounts-pro' ), $details['name'], $rejected );
		self::text_row( 'tax_id', __( 'VAT / tax ID', 'woo-organization-accounts-pro' ), $details['tax_id'], $rejected );

		echo '<tr><th scope="row"><label for="woap-status">' . esc_html__( 'Status', 'woo-organization-accounts-pro' ) . '</label></th><td><select id="woap-status" name="woap_status">';

		foreach ( Organization::statuses() as $status => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $status ),
				selected( $details['status'], $status, false ),
				esc_html( $label )
			);
		}

		echo '</select></td></tr>';

		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="woap_allow_custom_shipping" value="1"%2$s> %3$s</label></td></tr>',
			esc_html__( 'Shipping', 'woo-organization-accounts-pro' ),
			checked( $organization->allows_custom_shipping(), true, false ),
			esc_html__( 'Allow one-off shipping addresses at checkout', 'woo-organization-accounts-pro' )
		);

		echo '</tbody></table></div>';

		echo '<div><h2>' . esc_html__( 'Billing address', 'woo-organization-accounts-pro' ) . '</h2>';
		AddressFields::render( AddressFields::BILLING, $billing, array( 'errors' => $rejected ) );
		echo '</div>';
		echo '</div>';

		submit_button();
		echo '</form>';
	}

	/**
	 * The organization's members.
	 *
	 * @param Organization $organization The organization.
	 * @return void
	 */
	private function render_members( Organization $organization ) {
		printf( '<h2>%s</h2>', esc_html( Labels::members() ) );

		$members = MemberRepository::for_organization( $organization->get_id() );

		if ( empty( $members ) ) {
			printf( '<p>%s</p>', esc_html__( 'Nobody yet.', 'woo-organization-accounts-pro' ) );

			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		printf( '<th>%s</th>', esc_html__( 'Name', 'woo-organization-accounts-pro' ) );
		printf( '<th>%s</th>', esc_html__( 'Email address', 'woo-organization-accounts-pro' ) );
		printf( '<th>%s</th>', esc_html__( 'Role', 'woo-organization-accounts-pro' ) );
		printf( '<th>%s</th>', esc_html__( 'Status', 'woo-organization-accounts-pro' ) );
		echo '</tr></thead><tbody>';

		foreach ( $members as $member ) {
			$user = get_user_by( 'id', $member->get_user_id() );

			echo '<tr>';
			printf(
				'<td>%s</td>',
				$user instanceof \WP_User
					? sprintf( '<a href="%1$s">%2$s</a>', esc_url( get_edit_user_link( $user->ID ) ), esc_html( $user->display_name ) )
					: esc_html__( '(deleted account)', 'woo-organization-accounts-pro' )
			);
			printf( '<td>%s</td>', esc_html( $user instanceof \WP_User ? $user->user_email : '' ) );
			printf( '<td>%s</td>', esc_html( $member->is_admin() ? Labels::organization_admin() : Labels::member() ) );
			printf(
				'<td>%s</td>',
				esc_html( $member->is_active() ? __( 'Active', 'woo-organization-accounts-pro' ) : __( 'Inactive', 'woo-organization-accounts-pro' ) )
			);
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * The organization's locations.
	 *
	 * @param Organization $organization The organization.
	 * @return void
	 */
	private function render_locations( Organization $organization ) {
		printf( '<h2>%s</h2>', esc_html( Labels::locations() ) );

		$locations = LocationRepository::for_organization( $organization->get_id() );

		if ( empty( $locations ) ) {
			printf( '<p>%s</p>', esc_html__( 'None yet.', 'woo-organization-accounts-pro' ) );

			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		printf( '<th>%s</th>', esc_html__( 'Name', 'woo-organization-accounts-pro' ) );
		printf( '<th>%s</th>', esc_html__( 'Address', 'woo-organization-accounts-pro' ) );
		printf( '<th>%s</th>', esc_html__( 'Contact', 'woo-organization-accounts-pro' ) );
		echo '</tr></thead><tbody>';

		foreach ( $locations as $location ) {
			echo '<tr>';
			printf( '<td>%s</td>', esc_html( $location->get_name() ) );
			printf( '<td>%s</td>', wp_kses_post( $location->get_formatted_address() ) );
			printf( '<td>%s</td>', esc_html( $location->get_contact_name() ) );
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * The organization's invitations.
	 *
	 * @param Organization $organization The organization.
	 * @return void
	 */
	private function render_invitations( Organization $organization ) {
		printf( '<h2>%s</h2>', esc_html__( 'Invitations', 'woo-organization-accounts-pro' ) );

		$invitations = InvitationRepository::for_organization( $organization->get_id() );

		if ( empty( $invitations ) ) {
			printf( '<p>%s</p>', esc_html__( 'None sent.', 'woo-organization-accounts-pro' ) );

			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		printf( '<th>%s</th>', esc_html__( 'Email address', 'woo-organization-accounts-pro' ) );
		printf( '<th>%s</th>', esc_html__( 'Role', 'woo-organization-accounts-pro' ) );
		printf( '<th>%s</th>', esc_html__( 'Status', 'woo-organization-accounts-pro' ) );
		echo '</tr></thead><tbody>';

		foreach ( $invitations as $invitation ) {
			echo '<tr>';
			printf( '<td>%s</td>', esc_html( $invitation->get_email() ) );
			printf( '<td>%s</td>', esc_html( Member::ROLE_ADMIN === $invitation->get_role() ? Labels::organization_admin() : Labels::member() ) );
			printf( '<td>%s</td>', esc_html( $invitation->get_status_label() ) );
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * The organization's most recent orders.
	 *
	 * @param Organization $organization The organization.
	 * @param \WC_Order[]  $orders       Orders to list.
	 * @return void
	 */
	private function render_orders( Organization $organization, array $orders ) {
		unset( $organization );

		printf( '<h2>%s</h2>', esc_html__( 'Recent orders', 'woo-organization-accounts-pro' ) );

		if ( empty( $orders ) ) {
			printf( '<p>%s</p>', esc_html__( 'No orders yet.', 'woo-organization-accounts-pro' ) );

			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		printf( '<th>%s</th>', esc_html__( 'Order', 'woo-organization-accounts-pro' ) );
		printf( '<th>%s</th>', esc_html__( 'Date', 'woo-organization-accounts-pro' ) );
		printf( '<th>%s</th>', esc_html( Labels::location() ) );
		printf( '<th>%s</th>', esc_html__( 'Status', 'woo-organization-accounts-pro' ) );
		printf( '<th>%s</th>', esc_html__( 'Total', 'woo-organization-accounts-pro' ) );
		echo '</tr></thead><tbody>';

		foreach ( $orders as $order ) {
			echo '<tr>';
			printf(
				'<td><a href="%1$s">#%2$s</a></td>',
				esc_url( $order->get_edit_order_url() ),
				esc_html( $order->get_order_number() )
			);
			printf( '<td>%s</td>', esc_html( $order->get_date_created() ? wc_format_datetime( $order->get_date_created() ) : '' ) );
			printf( '<td>%s</td>', esc_html( OrderMeta::location_name( $order ) ) );
			printf( '<td>%s</td>', esc_html( wc_get_order_status_name( $order->get_status() ) ) );
			printf( '<td>%s</td>', wp_kses_post( $order->get_formatted_order_total() ) );
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Change one organization's status.
	 *
	 * @return void
	 */
	public function handle_set_status() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified by check_admin_referer() below, which needs the ID to build the action.
		$organization_id = isset( $_GET['organization_id'] ) ? absint( wp_unslash( $_GET['organization_id'] ) ) : 0;

		check_admin_referer( 'woap_admin_set_status_' . $organization_id );
		self::require_capability();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified immediately above.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

		OrganizationRepository::set_status( $organization_id, $status );

		/*
		 * Back to the screen the decision was made on. A reviewer working the approvals
		 * queue wants the next one, not the record they have just finished with — and the
		 * detail screen is exactly where somebody who pressed Approve from *there* expects
		 * to stay. The value is an allow-listed slug rather than a URL, so nothing here can
		 * be pointed at another site.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified above; this only chooses which of our own screens to return to.
		$return_to = isset( $_GET['woap_return'] ) ? sanitize_key( wp_unslash( $_GET['woap_return'] ) ) : '';

		if ( Approvals::PAGE_SLUG === $return_to ) {
			wp_safe_redirect( Approvals::url() );
			exit;
		}

		self::go_back( $organization_id );
	}

	/**
	 * Change the status of everything ticked in the list.
	 *
	 * Runs on the screen's `load-` hook, so it fires on every view of the list and has
	 * to decide for itself whether this request is a submission. That is the same
	 * question wp-admin's own list screens answer with `current_action()`, and the nonce
	 * is checked the moment the answer is yes — never before, or an ordinary visit to
	 * the screen would be refused for carrying no nonce.
	 *
	 * @return void
	 */
	public function handle_bulk_status() {
		$action = self::bulk_action();

		if ( '' === $action ) {
			return;
		}

		check_admin_referer( 'bulk-' . OrganizationsListTable::PLURAL );
		self::require_capability();

		$map = array(
			'approve' => Organization::STATUS_ACTIVE,
			'suspend' => Organization::STATUS_SUSPENDED,
			'reject'  => Organization::STATUS_REJECTED,
		);

		if ( ! isset( $map[ $action ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified by check_admin_referer() above.
		$ids = isset( $_GET['organization_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_GET['organization_ids'] ) ) : array();

		foreach ( $ids as $organization_id ) {
			OrganizationRepository::set_status( $organization_id, $map[ $action ] );
		}

		/*
		 * Back to the list as a GET, so a refresh does not re-apply the action — and
		 * back to the *filtered* list, because acting on everything pending and landing
		 * on all organizations loses the place the work was being done from.
		 */
		wp_safe_redirect( self::list_url() );
		exit;
	}

	/**
	 * Save the detail screen.
	 *
	 * @return void
	 */
	public function handle_save() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer() below, which needs the ID to build the action.
		$organization_id = isset( $_POST['organization_id'] ) ? absint( wp_unslash( $_POST['organization_id'] ) ) : 0;

		check_admin_referer( 'woap_admin_save_' . $organization_id );
		self::require_capability();

		$organization = OrganizationRepository::find( $organization_id );

		if ( null === $organization ) {
			self::go_back( 0 );
		}

		$previous = $organization->get_status();

		$details = array(
			'name'   => self::posted( 'woap_name' ),
			'tax_id' => self::posted( 'woap_tax_id' ),
			'status' => self::posted( 'woap_status' ),
		);

		$errors  = new \WP_Error();
		$address = AddressFields::posted( AddressFields::BILLING );

		/*
		 * Both halves are checked before either is written, and the whole submission is
		 * handed back if either fails. Validating only the address let an empty name
		 * through the same screen that refused a bad postcode.
		 */
		Organization::validate_details( $details, $errors );
		AddressFields::validate( AddressFields::BILLING, $address, $errors );

		if ( $errors->has_errors() ) {
			/*
			 * The whole submission is parked, not only the messages. admin-post.php has
			 * to redirect, and redirecting with nothing but a notice is how somebody
			 * loses a fourteen-field address to one mistyped postcode.
			 */
			$parked = array();

			foreach ( $details as $field => $value ) {
				$parked[ 'woap_' . $field ] = $value;
			}

			foreach ( $address as $field => $value ) {
				$parked[ AddressFields::BILLING . '_' . $field ] = $value;
			}

			set_transient(
				self::NOTICE_TRANSIENT . get_current_user_id(),
				array(
					'errors' => $errors->errors,
					'values' => $parked,
				),
				MINUTE_IN_SECONDS
			);

			self::go_back( $organization_id );
		}

		$status = $details['status'];
		unset( $details['status'] );

		$organization->set_props(
			array_merge(
				$details,
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer() above.
				array( 'allow_custom_shipping' => ! empty( $_POST['woap_allow_custom_shipping'] ) )
			)
		);

		$organization->set_billing_address( $address );
		OrganizationRepository::save( $organization );

		/*
		 * The status is applied through the repository rather than with the rest of the
		 * form, because a status change fires the hook the approval and rejection emails
		 * hang off, and a plain save must not.
		 */
		if ( $status !== $previous ) {
			OrganizationRepository::set_status( $organization_id, $status );
		}

		self::go_back( $organization_id );
	}

	/**
	 * Delete an organization and everything belonging to it.
	 *
	 * Orders are never touched: they are the shop's records, not the organization's,
	 * and they carry their own snapshot of the addresses they were placed with.
	 *
	 * @return void
	 */
	public function handle_delete() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified by check_admin_referer() below, which needs the ID to build the action.
		$organization_id = isset( $_GET['organization_id'] ) ? absint( wp_unslash( $_GET['organization_id'] ) ) : 0;

		check_admin_referer( 'woap_admin_delete_' . $organization_id );
		self::require_capability();

		foreach ( MemberRepository::for_organization( $organization_id ) as $member ) {
			$user = get_user_by( 'id', $member->get_user_id() );

			if ( $user instanceof \WP_User ) {
				$user->set_role( 'customer' );
			}
		}

		MemberRepository::delete_for_organization( $organization_id );
		LocationRepository::delete_for_organization( $organization_id );
		InvitationRepository::delete_for_organization( $organization_id );
		OrganizationRepository::delete( $organization_id );

		self::go_back( 0 );
	}

	/**
	 * The bulk action the list submitted, from either select.
	 *
	 * Read before any nonce check, because it is what decides whether this request is a
	 * submission at all — so it names an action and nothing more. Nothing acts on the
	 * answer until `check_admin_referer()` has run.
	 *
	 * @return string Action name, or an empty string.
	 */
	private static function bulk_action() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Only decides whether a nonce is required; the caller verifies one before acting.
		foreach ( array( 'action', 'action2' ) as $field ) {
			if ( ! isset( $_GET[ $field ] ) ) {
				continue;
			}

			$value = sanitize_key( wp_unslash( $_GET[ $field ] ) );

			if ( '' !== $value && '-1' !== $value ) {
				return $value;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return '';
	}

	/**
	 * Stop unless the current user administers the shop.
	 *
	 * @return void
	 */
	private static function require_capability() {
		if ( current_user_can( self::CAPABILITY ) ) {
			return;
		}

		wp_die(
			esc_html__( 'You do not have permission to do that.', 'woo-organization-accounts-pro' ),
			esc_html__( 'Permission denied', 'woo-organization-accounts-pro' ),
			array( 'response' => 403 )
		);
	}

	/**
	 * Read a posted text field.
	 *
	 * @param string $key Field name.
	 * @return string Sanitised value.
	 */
	private static function posted( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer() in the caller.
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/**
	 * Return to the detail screen, or to the list.
	 *
	 * @param int $organization_id Organization to return to, or 0 for the list.
	 * @return void
	 */
	private static function go_back( $organization_id ) {
		$url = $organization_id > 0
			? self::edit_url( $organization_id )
			: self::list_url();

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Print anything the last save had to say, once, and hand back what it rejected.
	 *
	 * @return array {
	 *     @type \WP_Error|null $0 The errors, or null when the last save was fine.
	 *     @type array          $1 The rejected values, keyed by prefixed field name.
	 * }
	 */
	private static function render_notices() {
		$key    = self::NOTICE_TRANSIENT . get_current_user_id();
		$parked = get_transient( $key );

		if ( empty( $parked['errors'] ) || ! is_array( $parked['errors'] ) ) {
			return array( null, array() );
		}

		delete_transient( $key );

		$errors = new \WP_Error();

		echo '<div class="notice notice-error"><ul>';

		foreach ( $parked['errors'] as $code => $messages ) {
			foreach ( (array) $messages as $message ) {
				$errors->add( $code, $message );
				printf( '<li>%s</li>', wp_kses_post( $message ) );
			}
		}

		echo '</ul></div>';

		return array( $errors, isset( $parked['values'] ) ? (array) $parked['values'] : array() );
	}

	/**
	 * Print one text row of the detail form.
	 *
	 * The field is posted under its `woap_` prefix, because that is what `handle_save()`
	 * reads and because every field this plugin defines is prefixed. Emitting the bare
	 * column name here left the save reading four fields nothing had submitted, so every
	 * save wrote an empty name, email, phone and tax ID over whatever was stored.
	 *
	 * @param string         $name     Column name, without its prefix.
	 * @param string         $label    Field label.
	 * @param string         $value    Current value.
	 * @param \WP_Error|null $rejected Errors from a rejected save, if any.
	 * @return void
	 */
	private static function text_row( $name, $label, $value, $rejected = null ) {
		$message = $rejected instanceof \WP_Error ? $rejected->get_error_message( 'woap_' . $name ) : '';

		printf(
			'<tr class="%4$s"><th scope="row"><label for="woap-%1$s">%2$s</label></th><td><input type="text" class="regular-text" id="woap-%1$s" name="woap_%1$s" value="%3$s">%5$s</td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $value ),
			esc_attr( '' !== $message ? 'woap-row--invalid' : '' ),
			'' !== $message
				? '<p class="woap-field-error">' . wp_kses_post( $message ) . '</p>'
				: ''
		);
	}
}
