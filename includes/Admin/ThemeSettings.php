<?php
/**
 * Woodmart Theme Settings integration.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Admin;

use WooOrgAccounts\Labels;
use WooOrgAccounts\LoginGate;
use XTS\Admin\Modules\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Surface the plugin's configuration inside Woodmart Theme Settings.
 *
 * A shop running this plugin is a Woodmart shop, and its owner configures the shop in
 * Woodmart → Theme Settings. Leaving the organization settings out of that screen
 * entirely means the one panel that changes what every noun on the site is called is
 * the one panel they never find.
 *
 * **The settings are shown here, not stored here.** Woodmart keeps its whole options
 * screen in a single `xts-woodmart-options` array, written in one update_option() call,
 * with a "Reset to default" action and a demo-content importer that both overwrite that
 * array wholesale. Two of these settings would not survive that: `organization_mode`
 * renames every organization noun on the site, and `require_approval` decides whether a
 * newly registered organization may buy anything. Importing a demo to try a homepage
 * layout must not quietly reopen the shop or rename half of it.
 *
 * Registering editable copies would also leave two answers to one question with no
 * defined winner — the same reason `Capabilities` grants from the membership row rather
 * than the role, and the same reason there is no organization type column. So this
 * section reads the plugin's own option and links to the screen that writes it.
 */
class ThemeSettings {

	/**
	 * Identifier of the section this adds to Woodmart's settings.
	 */
	const SECTION = 'woap_organization_accounts_section';

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		/*
		 * Woodmart loads its own sections and fields on init at priority 5. Anything
		 * earlier than that has no parent section to attach to.
		 */
		add_action( 'init', array( $this, 'add_settings' ), 20 );
	}

	/**
	 * Add the section and its contents.
	 *
	 * @return void
	 */
	public function add_settings() {
		if ( ! class_exists( Options::class ) || ! current_user_can( Settings::CAPABILITY ) ) {
			return;
		}

		Options::add_section(
			array(
				'id'       => self::SECTION,
				'parent'   => 'my_account',
				'name'     => __( 'Organization Accounts', 'woo-organization-accounts-pro' ),
				'priority' => 40,
				'icon'     => 'xts-i-login',
			)
		);

		Options::add_field(
			array(
				'id'       => 'woap_current_settings',
				'type'     => 'notice',
				'style'    => 'default',
				'name'     => '',
				'content'  => self::summary(),
				'section'  => self::SECTION,
				'priority' => 10,
			)
		);
	}

	/**
	 * The panel's contents: what the plugin is currently set to, and where to change it.
	 *
	 * @return string Markup for the notice control, already escaped.
	 */
	private static function summary() {
		$modes = Labels::modes();
		$mode  = Labels::mode();

		$rows = array(
			__( 'Mode', 'woo-organization-accounts-pro' ) => isset( $modes[ $mode ] ) ? $modes[ $mode ] : $mode,
			__( 'Approval', 'woo-organization-accounts-pro' ) => Settings::get( 'require_approval', true )
				? __( 'New registrations wait for an administrator', 'woo-organization-accounts-pro' )
				: __( 'New registrations are active immediately', 'woo-organization-accounts-pro' ),
			__( 'Signing in', 'woo-organization-accounts-pro' ) => LoginGate::is_enabled()
				? sprintf(
					/* translators: %s: the plural organization noun for the site's mode. */
					__( 'Only members of approved %s may sign in', 'woo-organization-accounts-pro' ),
					Labels::organizations()
				)
				: __( 'Anybody with an account may sign in', 'woo-organization-accounts-pro' ),
			__( 'Registration page', 'woo-organization-accounts-pro' ) => self::registration_page_label(),
		);

		$markup = '<p>' . esc_html__( 'This shop uses organization accounts. Customers belong to an organization, billing is centralised on it, and there is no guest checkout.', 'woo-organization-accounts-pro' ) . '</p><ul>';

		foreach ( $rows as $label => $value ) {
			$markup .= sprintf(
				'<li><strong>%1$s:</strong> %2$s</li>',
				esc_html( $label ),
				esc_html( $value )
			);
		}

		$markup .= '</ul>';

		$markup .= sprintf(
			'<p><a href="%1$s" class="xts-btn xts-color-primary">%2$s</a></p>',
			esc_url( admin_url( 'admin.php?page=' . Settings::PAGE_SLUG ) ),
			esc_html__( 'Change these settings', 'woo-organization-accounts-pro' )
		);

		$markup .= '<p>' . esc_html__( 'They are kept on the plugin\'s own screen rather than here, so that resetting Theme Settings or importing a demo cannot rename your organizations or let unapproved accounts start buying.', 'woo-organization-accounts-pro' ) . '</p>';

		return $markup;
	}

	/**
	 * The configured registration page, by title.
	 *
	 * @return string Translated description of the current setting.
	 */
	private static function registration_page_label() {
		$page_id = absint( Settings::get( 'registration_page_id', 0 ) );
		$title   = $page_id > 0 ? get_the_title( $page_id ) : '';

		if ( '' === $title ) {
			return __( 'Not set', 'woo-organization-accounts-pro' );
		}

		return $title;
	}
}
