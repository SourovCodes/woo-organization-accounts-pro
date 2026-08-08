<?php
/**
 * Uninstall routine.
 *
 * Runs when the plugin is deleted from the Plugins screen. WordPress loads this file
 * directly, so nothing from the plugin's own bootstrap is available here — not the
 * autoloader, not the constants, not a single class. Everything below is written
 * against WordPress and $wpdb alone.
 *
 * @package WooOrgAccounts
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$woap_settings = get_option( 'woo_org_accounts_settings', array() );
$woap_purge    = is_array( $woap_settings ) && ! empty( $woap_settings['remove_data_on_uninstall'] );

/*
 * Members keep their WordPress accounts, but not a role whose capabilities no longer
 * resolve to anything. Moving them to WooCommerce's customer role leaves them able to
 * sign in and see their own order history, which is the least surprising outcome for
 * somebody who never installed or removed anything.
 */
foreach ( get_users( array( 'role__in' => array( 'woap_org_admin', 'woap_member' ) ) ) as $woap_user ) {
	$woap_user->set_role( 'customer' );
}

remove_role( 'woap_org_admin' );
remove_role( 'woap_member' );

delete_option( 'woo_org_accounts_settings' );
delete_option( 'woo_org_accounts_version' );
delete_site_transient( 'woap_update_manifest' );

/*
 * The organizations, their members, locations and invitations are the shop's B2B
 * customer records. Deleting a plugin is not a decision to delete its customers, so
 * they survive unless the site explicitly asked for them to go — the setting is off by
 * default, and turning it on says so in as many words.
 */
if ( $woap_purge ) {
	global $wpdb;

	$woap_tables = array(
		'woap_member_locations',
		'woap_invitations',
		'woap_locations',
		'woap_members',
		'woap_organizations',
	);

	foreach ( $woap_tables as $woap_table ) {
		$woap_name = $wpdb->prefix . $woap_table;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- A table name cannot be a placeholder; this one is built from a literal in the list above.
		$wpdb->query( "DROP TABLE IF EXISTS {$woap_name}" );
	}

	delete_option( 'woo_org_accounts_db_version' );
}

/*
 * The _woap_* meta on orders is never removed, whatever the setting says. It is part
 * of the order — which organization placed it, which location it went to, who bought
 * it — and an order is a financial record the shop may be required to keep intact. The
 * registration page is left alone for the same reason a page is: somebody may have
 * edited it, and deleting their content is not this routine's business.
 */
