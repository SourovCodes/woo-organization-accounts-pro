<?php
/**
 * Activation routine.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts;

use WooOrgAccounts\Admin\Settings;
use WooOrgAccounts\Frontend\MyAccount;
use WooOrgAccounts\Frontend\Registration;

defined( 'ABSPATH' ) || exit;

/**
 * Runs once when the plugin is activated.
 */
final class Activator {

	/**
	 * Prepare the site for the plugin.
	 *
	 * Keep this idempotent: WordPress runs it on every activation, including
	 * reactivation after an update.
	 *
	 * @return void
	 */
	public static function activate() {
		// Seed the settings so every screen has a complete array to read. Autoload is
		// off because the settings are read on account and checkout pages, not on
		// every request the site serves.
		add_option( Settings::OPTION_KEY, Settings::default_settings(), '', false );
		add_option( 'woo_org_accounts_version', WOAP_VERSION, '', false );

		Install::install();
		Roles::install();

		Registration::create_page();

		/*
		 * The My Account endpoints are rewrite rules, and a rewrite rule that has not
		 * been flushed is a 404. Registering them first is what gives the flush
		 * something to write.
		 */
		MyAccount::add_endpoints();
		flush_rewrite_rules();

		/**
		 * Fires after the plugin has finished its activation routine.
		 *
		 * @since 0.1.0
		 */
		do_action( 'woo_org_accounts_activated' );
	}
}
