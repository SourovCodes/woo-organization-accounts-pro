<?php
/**
 * Admin settings screen.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Admin;

use WooOrgAccounts\Frontend\Registration;
use WooOrgAccounts\Labels;
use WooOrgAccounts\Updates\Updater;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's settings and its screen under WooCommerce.
 */
class Settings {

	/**
	 * Option name holding every plugin setting.
	 */
	const OPTION_KEY = 'woo_org_accounts_settings';

	/**
	 * Settings group used by the Settings API.
	 */
	const OPTION_GROUP = 'woo_org_accounts_settings_group';

	/**
	 * Menu slug of the settings screen.
	 */
	const PAGE_SLUG = 'woo-organization-accounts';

	/**
	 * Capability required to view or change the settings.
	 */
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * Invitation expiry value meaning "these invitations never expire".
	 */
	const EXPIRY_NEVER = 0;

	/**
	 * Hook suffix of the settings screen, used to scope asset loading.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * The default settings.
	 *
	 * @return array Default settings array.
	 */
	public static function default_settings() {
		return array(
			'organization_mode'             => Labels::MODE_BUSINESS,
			'require_approval'              => true,
			'require_approval_to_sign_in'   => false,
			'require_tax_id'                => false,
			'invitation_expiry_days'        => 7,
			'registration_page_id'          => 0,
			'default_allow_custom_shipping' => true,
			'remove_data_on_uninstall'      => false,
		);
	}

	/**
	 * Retrieve the settings, merged over the defaults.
	 *
	 * @return array Complete settings array.
	 */
	public static function get_settings() {
		$stored = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return wp_parse_args( $stored, self::default_settings() );
	}

	/**
	 * Read one setting.
	 *
	 * @param string $key      Setting name.
	 * @param mixed  $fallback Returned when the setting does not exist.
	 * @return mixed Setting value.
	 */
	public static function get( $key, $fallback = null ) {
		$settings = self::get_settings();

		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $fallback;
	}

	/**
	 * How long a new invitation is valid for, in seconds.
	 *
	 * @return int Seconds, or 0 when invitations do not expire.
	 */
	public static function invitation_lifetime() {
		$days = absint( self::get( 'invitation_expiry_days', 7 ) );

		return $days * DAY_IN_SECONDS;
	}

	/**
	 * Register the admin hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_woap_check_updates', array( $this, 'handle_check_updates' ) );
	}

	/**
	 * Register the option with the Settings API.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::default_settings(),
			)
		);

		add_settings_section(
			'woap_mode',
			__( 'Organization mode', 'woo-organization-accounts-pro' ),
			array( $this, 'render_mode_intro' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'organization_mode',
			__( 'Mode', 'woo-organization-accounts-pro' ),
			array( $this, 'render_mode_field' ),
			self::PAGE_SLUG,
			'woap_mode'
		);

		add_settings_section(
			'woap_registration',
			__( 'Registration', 'woo-organization-accounts-pro' ),
			array( $this, 'render_registration_intro' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'require_approval',
			__( 'Approval', 'woo-organization-accounts-pro' ),
			array( $this, 'render_require_approval_field' ),
			self::PAGE_SLUG,
			'woap_registration'
		);

		add_settings_field(
			'registration_page_id',
			__( 'Registration page', 'woo-organization-accounts-pro' ),
			array( $this, 'render_registration_page_field' ),
			self::PAGE_SLUG,
			'woap_registration'
		);

		add_settings_field(
			'require_tax_id',
			__( 'VAT / tax ID', 'woo-organization-accounts-pro' ),
			array( $this, 'render_require_tax_id_field' ),
			self::PAGE_SLUG,
			'woap_registration'
		);

		add_settings_field(
			'invitation_expiry_days',
			__( 'Invitations expire after', 'woo-organization-accounts-pro' ),
			array( $this, 'render_invitation_expiry_field' ),
			self::PAGE_SLUG,
			'woap_registration'
		);

		add_settings_section(
			'woap_purchasing',
			__( 'Purchasing', 'woo-organization-accounts-pro' ),
			array( $this, 'render_purchasing_intro' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'default_allow_custom_shipping',
			__( 'Custom shipping addresses', 'woo-organization-accounts-pro' ),
			array( $this, 'render_custom_shipping_field' ),
			self::PAGE_SLUG,
			'woap_purchasing'
		);

		add_settings_section(
			'woap_data',
			__( 'Data', 'woo-organization-accounts-pro' ),
			array( $this, 'render_data_intro' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'remove_data_on_uninstall',
			__( 'On uninstall', 'woo-organization-accounts-pro' ),
			array( $this, 'render_remove_data_field' ),
			self::PAGE_SLUG,
			'woap_data'
		);
	}

	/**
	 * Add the settings screen under the plugin's own menu.
	 *
	 * The slug is unchanged from when this sat under WooCommerce. It is the address the
	 * Woodmart theme panel links to and the one a shop is likely to have bookmarked, and a
	 * screen's parent is not part of its URL.
	 *
	 * @return void
	 */
	public function register_menu() {
		$this->hook_suffix = add_submenu_page(
			Menu::PAGE_SLUG,
			__( 'Settings', 'woo-organization-accounts-pro' ),
			__( 'Settings', 'woo-organization-accounts-pro' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Load the screen's stylesheet, and only on the screen.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'woap-admin',
			WOAP_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			WOAP_VERSION
		);
	}

	/**
	 * Validate and normalise the submitted settings.
	 *
	 * Everything is read from the declared defaults rather than from whatever was
	 * posted, so a hand-crafted form cannot introduce a setting the plugin does not
	 * have.
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array Clean settings array.
	 */
	public function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$current  = self::get_settings();
		$defaults = self::default_settings();
		$clean    = array();

		$mode                       = isset( $input['organization_mode'] ) ? sanitize_key( $input['organization_mode'] ) : '';
		$clean['organization_mode'] = array_key_exists( $mode, Labels::modes() ) ? $mode : $defaults['organization_mode'];

		$clean['require_approval']              = ! empty( $input['require_approval'] );
		$clean['require_approval_to_sign_in']   = ! empty( $input['require_approval_to_sign_in'] );
		$clean['require_tax_id']                = ! empty( $input['require_tax_id'] );
		$clean['default_allow_custom_shipping'] = ! empty( $input['default_allow_custom_shipping'] );
		$clean['remove_data_on_uninstall']      = ! empty( $input['remove_data_on_uninstall'] );

		$clean['invitation_expiry_days'] = isset( $input['invitation_expiry_days'] )
			? min( 365, absint( $input['invitation_expiry_days'] ) )
			: $defaults['invitation_expiry_days'];

		$page = isset( $input['registration_page_id'] ) ? absint( $input['registration_page_id'] ) : 0;

		$clean['registration_page_id'] = ( $page > 0 && 'page' === get_post_type( $page ) )
			? $page
			: $current['registration_page_id'];

		return $clean;
	}

	/**
	 * Explain what the organization mode does.
	 *
	 * @return void
	 */
	public function render_mode_intro() {
		echo '<p>';
		esc_html_e(
			'The mode applies to the whole site and decides what everything is called. It changes labels only — no data is stored differently, and nothing needs migrating when you change it.',
			'woo-organization-accounts-pro'
		);
		echo '</p>';
	}

	/**
	 * The organization mode selector.
	 *
	 * @return void
	 */
	public function render_mode_field() {
		$settings = self::get_settings();

		echo '<fieldset>';

		foreach ( Labels::modes() as $value => $label ) {
			printf(
				'<label><input type="radio" name="%1$s[organization_mode]" value="%2$s"%3$s> %4$s</label><br>',
				esc_attr( self::OPTION_KEY ),
				esc_attr( $value ),
				checked( $settings['organization_mode'], $value, false ),
				esc_html( $label )
			);
		}

		echo '</fieldset>';

		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: organization noun, 2: admin noun, 3: plural member noun, 4: plural location noun. */
					__( 'Currently: %1$s, %2$s, %3$s, %4$s.', 'woo-organization-accounts-pro' ),
					Labels::organization(),
					Labels::organization_admin(),
					Labels::members(),
					Labels::locations()
				)
			)
		);
	}

	/**
	 * Explain the registration settings.
	 *
	 * @return void
	 */
	public function render_registration_intro() {
		echo '<p>';
		esc_html_e(
			'WooCommerce\'s own registration form is switched off while this plugin is active: every account belongs to an organization, so accounts are created here or through an invitation.',
			'woo-organization-accounts-pro'
		);
		echo '</p>';
	}

	/**
	 * The approval requirement checkboxes.
	 *
	 * Two separate questions, because approval gates two different things. The first
	 * decides whether a new registration may *order*; the second decides whether its
	 * members may *sign in* at all. They are deliberately not folded into one: a shop
	 * that lets a pending organization sign in and browse is a different shop from one
	 * that holds the account shut until somebody has looked at it, and both are asked
	 * for.
	 *
	 * The second one is not conditional on the first, either. An organization reaches a
	 * status other than active by being suspended or rejected as well as by waiting for
	 * approval, and those are worth locking out on a shop that approves nothing.
	 *
	 * @return void
	 */
	public function render_require_approval_field() {
		$settings = self::get_settings();

		echo '<fieldset>';

		printf(
			'<label><input type="checkbox" name="%1$s[require_approval]" value="1"%2$s> %3$s</label>',
			esc_attr( self::OPTION_KEY ),
			checked( $settings['require_approval'], true, false ),
			esc_html(
				sprintf(
					/* translators: %s: the plural organization noun for the site's mode. */
					__( 'New %s must be approved before they can order', 'woo-organization-accounts-pro' ),
					Labels::organizations()
				)
			)
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'With this off, a new registration is active immediately.', 'woo-organization-accounts-pro' )
		);

		printf(
			'<br><label><input type="checkbox" name="%1$s[require_approval_to_sign_in]" value="1"%2$s> %3$s</label>',
			esc_attr( self::OPTION_KEY ),
			checked( $settings['require_approval_to_sign_in'], true, false ),
			esc_html(
				sprintf(
					/* translators: %s: the plural organization noun for the site's mode. */
					__( 'Only members of approved %s may sign in', 'woo-organization-accounts-pro' ),
					Labels::organizations()
				)
			)
		);

		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: the organization noun for the site's mode. */
					__( 'With this on, a new registration is not signed in afterwards: it is told the %s is being reviewed, and can sign in once it has been approved. Shop staff are never locked out.', 'woo-organization-accounts-pro' ),
					Labels::organization()
				)
			)
		);

		echo '</fieldset>';
	}

	/**
	 * The registration page selector.
	 *
	 * @return void
	 */
	public function render_registration_page_field() {
		$settings = self::get_settings();

		/*
		 * The sniff treats wp_dropdown_pages() as a printing function whatever its
		 * arguments say; with 'echo' => false it returns markup instead, and the echo
		 * below is where the escaping question is actually answered.
		 */
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		$dropdown = wp_dropdown_pages(
			array(
				'name'              => self::OPTION_KEY . '[registration_page_id]',
				'selected'          => (int) $settings['registration_page_id'],
				'show_option_none'  => __( '— Select —', 'woo-organization-accounts-pro' ),
				'option_none_value' => 0,
				'echo'              => false,
			)
		);

		// Markup generated by WordPress, which escapes the page titles it puts in it.
		echo $dropdown;
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: the registration shortcode. */
					__( 'The page containing the %s shortcode. One was created for you on activation.', 'woo-organization-accounts-pro' ),
					'[' . Registration::SHORTCODE . ']'
				)
			)
		);
	}

	/**
	 * The tax ID requirement checkbox.
	 *
	 * @return void
	 */
	public function render_require_tax_id_field() {
		$settings = self::get_settings();

		printf(
			'<label><input type="checkbox" name="%1$s[require_tax_id]" value="1"%2$s> %3$s</label>',
			esc_attr( self::OPTION_KEY ),
			checked( $settings['require_tax_id'], true, false ),
			esc_html(
				sprintf(
					/* translators: %s: the plural organization noun for the site's mode. */
					__( '%s must supply a VAT number, tax ID or registration number', 'woo-organization-accounts-pro' ),
					Labels::organizations()
				)
			)
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__(
				'Applied on registration and on both edit screens. The number is stored and shown as it was typed; no format is checked, because a VAT number, a company registration number and a US EIN look nothing alike.',
				'woo-organization-accounts-pro'
			)
		);
	}

	/**
	 * The invitation expiry field.
	 *
	 * @return void
	 */
	public function render_invitation_expiry_field() {
		$settings = self::get_settings();

		printf(
			'<input type="number" class="small-text" min="0" max="365" step="1" name="%1$s[invitation_expiry_days]" value="%2$s"> %3$s',
			esc_attr( self::OPTION_KEY ),
			esc_attr( (string) $settings['invitation_expiry_days'] ),
			esc_html__( 'days', 'woo-organization-accounts-pro' )
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Zero means an invitation stays valid until it is used or revoked.', 'woo-organization-accounts-pro' )
		);
	}

	/**
	 * Explain the purchasing settings.
	 *
	 * @return void
	 */
	public function render_purchasing_intro() {
		echo '<p>';
		esc_html_e(
			'Guest checkout is switched off and cannot be switched back on while this plugin is active. Only a logged-in member of an active organization can check out.',
			'woo-organization-accounts-pro'
		);
		echo '</p>';
	}

	/**
	 * The default custom-shipping checkbox.
	 *
	 * @return void
	 */
	public function render_custom_shipping_field() {
		$settings = self::get_settings();

		printf(
			'<label><input type="checkbox" name="%1$s[default_allow_custom_shipping]" value="1"%2$s> %3$s</label>',
			esc_attr( self::OPTION_KEY ),
			checked( $settings['default_allow_custom_shipping'], true, false ),
			esc_html(
				sprintf(
					/* translators: %s: the plural organization noun for the site's mode. */
					__( 'New %s may enter a one-off shipping address at checkout', 'woo-organization-accounts-pro' ),
					Labels::organizations()
				)
			)
		);

		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: the plural organization noun for the site's mode. */
					__( 'This is the starting value for a new registration. It can be changed per %s afterwards.', 'woo-organization-accounts-pro' ),
					Labels::organization()
				)
			)
		);
	}

	/**
	 * Explain the data settings.
	 *
	 * @return void
	 */
	public function render_data_intro() {
		echo '<p>';
		esc_html_e( 'What happens to the plugin\'s data when it is deleted from the Plugins screen.', 'woo-organization-accounts-pro' );
		echo '</p>';
	}

	/**
	 * The uninstall behaviour checkbox.
	 *
	 * @return void
	 */
	public function render_remove_data_field() {
		$settings = self::get_settings();

		printf(
			'<label><input type="checkbox" name="%1$s[remove_data_on_uninstall]" value="1"%2$s> %3$s</label>',
			esc_attr( self::OPTION_KEY ),
			checked( $settings['remove_data_on_uninstall'], true, false ),
			esc_html(
				sprintf(
					/* translators: %s: the plural organization noun for the site's mode. */
					__( 'Delete every %s, member, location and invitation when the plugin is deleted', 'woo-organization-accounts-pro' ),
					Labels::organization()
				)
			)
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Off by default. These are customer records, and deleting the plugin should not delete them by accident. Orders and their addresses are never touched either way.', 'woo-organization-accounts-pro' )
		);
	}

	/**
	 * Render the settings screen.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		echo '<div class="wrap woap-settings">';
		printf( '<h1>%s</h1>', esc_html__( 'Organization Accounts', 'woo-organization-accounts-pro' ) );

		echo '<form action="options.php" method="post">';
		settings_fields( self::OPTION_GROUP );
		do_settings_sections( self::PAGE_SLUG );
		submit_button();
		echo '</form>';

		$this->render_updates_section();

		echo '</div>';
	}

	/**
	 * Render the update status and the manual re-check.
	 *
	 * Gated on `update_plugins` rather than on this screen's own capability: a shop
	 * manager may configure organizations here but may not install a plugin, so the
	 * whole section is hidden from them rather than offering an update they could not
	 * apply.
	 *
	 * @return void
	 */
	private function render_updates_section() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$status = Updater::status();

		printf( '<h2>%s</h2>', esc_html__( 'Updates', 'woo-organization-accounts-pro' ) );

		if ( 'available' === $status['state'] ) {
			printf(
				'<p>%s</p>',
				esc_html(
					sprintf(
						/* translators: 1: available version, 2: installed version. */
						__( 'Version %1$s is available. You are running %2$s.', 'woo-organization-accounts-pro' ),
						$status['version'],
						WOAP_VERSION
					)
				)
			);
		} elseif ( 'current' === $status['state'] ) {
			printf(
				'<p>%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: installed version. */
						__( 'You are running the latest version, %s.', 'woo-organization-accounts-pro' ),
						WOAP_VERSION
					)
				)
			);
		} else {
			printf(
				'<p>%s</p>',
				esc_html__( 'The last update check did not complete. WordPress.org or GitHub may have been unreachable.', 'woo-organization-accounts-pro' )
			);
		}

		printf( '<form action="%s" method="post">', esc_url( admin_url( 'admin-post.php' ) ) );
		echo '<input type="hidden" name="action" value="woap_check_updates">';
		wp_nonce_field( 'woap_check_updates' );
		submit_button( __( 'Check for updates', 'woo-organization-accounts-pro' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Handle the "Check for updates" button.
	 *
	 * @return void
	 */
	public function handle_check_updates() {
		check_admin_referer( 'woap_check_updates' );

		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to do that.', 'woo-organization-accounts-pro' ),
				esc_html__( 'Permission denied', 'woo-organization-accounts-pro' ),
				array( 'response' => 403 )
			);
		}

		( new Updater() )->refresh();

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}
}
