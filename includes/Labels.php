<?php
/**
 * Mode-dependent vocabulary.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts;

use WooOrgAccounts\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The nouns the frontend and the admin call things.
 *
 * The site runs in exactly one organization mode, chosen once in the settings, and
 * that choice changes what everything is *called* and nothing about what is stored.
 * A company and an educational institute are the same table, the same columns and the
 * same code path — so the mode lives in one option, not in a column on every row, and
 * there is no way for two organizations on one site to disagree about it.
 *
 * Every noun here is a separate translatable string per mode rather than one string
 * with the noun substituted in, because German has to be free to translate "Company"
 * and "Institute" independently. Sentences containing a noun use a printf placeholder
 * and take the noun from here, which keeps the number of strings finite.
 */
final class Labels {

	/**
	 * The site sells to businesses: companies, their employees and their branches.
	 */
	const MODE_BUSINESS = 'business';

	/**
	 * The site sells to educational institutes: institutes, their staff and campuses.
	 */
	const MODE_EDUCATION = 'education';

	/**
	 * The organization mode the site is running in.
	 *
	 * @return string One of the MODE_* constants.
	 */
	public static function mode() {
		$settings = Settings::get_settings();
		$mode     = isset( $settings['organization_mode'] ) ? (string) $settings['organization_mode'] : '';

		return self::MODE_EDUCATION === $mode ? self::MODE_EDUCATION : self::MODE_BUSINESS;
	}

	/**
	 * Whether the site is in educational institute mode.
	 *
	 * @return bool True in educational mode.
	 */
	public static function is_education() {
		return self::MODE_EDUCATION === self::mode();
	}

	/**
	 * The modes an administrator can choose between.
	 *
	 * @return array Map of mode to translated label.
	 */
	public static function modes() {
		return array(
			self::MODE_BUSINESS  => __( 'Business / Company', 'woo-organization-accounts-pro' ),
			self::MODE_EDUCATION => __( 'Educational Institute', 'woo-organization-accounts-pro' ),
		);
	}

	/**
	 * What one organization is called.
	 *
	 * @return string Translated noun.
	 */
	public static function organization() {
		return self::is_education()
			? __( 'Institute', 'woo-organization-accounts-pro' )
			: __( 'Company', 'woo-organization-accounts-pro' );
	}

	/**
	 * What several organizations are called.
	 *
	 * @return string Translated noun.
	 */
	public static function organizations() {
		return self::is_education()
			? __( 'Institutes', 'woo-organization-accounts-pro' )
			: __( 'Companies', 'woo-organization-accounts-pro' );
	}

	/**
	 * What an organization administrator is called.
	 *
	 * @return string Translated noun.
	 */
	public static function organization_admin() {
		return self::is_education()
			? __( 'Institute Admin', 'woo-organization-accounts-pro' )
			: __( 'Company Admin', 'woo-organization-accounts-pro' );
	}

	/**
	 * What several organization administrators are called.
	 *
	 * @return string Translated noun.
	 */
	public static function organization_admins() {
		return self::is_education()
			? __( 'Institute Admins', 'woo-organization-accounts-pro' )
			: __( 'Company Admins', 'woo-organization-accounts-pro' );
	}

	/**
	 * What one ordinary member is called.
	 *
	 * @return string Translated noun.
	 */
	public static function member() {
		return self::is_education()
			? __( 'Staff Member', 'woo-organization-accounts-pro' )
			: __( 'Employee', 'woo-organization-accounts-pro' );
	}

	/**
	 * What several members are called.
	 *
	 * @return string Translated noun.
	 */
	public static function members() {
		return self::is_education()
			? __( 'Staff', 'woo-organization-accounts-pro' )
			: __( 'Employees', 'woo-organization-accounts-pro' );
	}

	/**
	 * What one delivery location is called.
	 *
	 * @return string Translated noun.
	 */
	public static function location() {
		return self::is_education()
			? __( 'Campus', 'woo-organization-accounts-pro' )
			: __( 'Branch', 'woo-organization-accounts-pro' );
	}

	/**
	 * What several delivery locations are called.
	 *
	 * @return string Translated noun.
	 */
	public static function locations() {
		return self::is_education()
			? __( 'Campuses', 'woo-organization-accounts-pro' )
			: __( 'Branches', 'woo-organization-accounts-pro' );
	}
}
