<?php
/**
 * Template loading.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the plugin's frontend templates through WooCommerce's template system.
 *
 * Going through `wc_get_template()` rather than `include` is what makes every screen
 * this plugin adds overridable from a theme, in the place a WooCommerce theme author
 * already looks: `yourtheme/woo-organization-accounts/…`.
 */
final class Templates {

	/**
	 * Directory inside a theme that overrides these templates.
	 */
	const THEME_PATH = 'woo-organization-accounts/';

	/**
	 * The plugin's own template directory.
	 *
	 * @return string Absolute path, with a trailing slash.
	 */
	public static function path() {
		return WOAP_PLUGIN_DIR . 'templates/';
	}

	/**
	 * Render a template.
	 *
	 * @param string $template Template file, relative to the templates directory.
	 * @param array  $args     Variables made available to the template.
	 * @return void
	 */
	public static function render( $template, array $args = array() ) {
		wc_get_template( $template, $args, self::THEME_PATH, self::path() );
	}

	/**
	 * Render a template and return the result.
	 *
	 * @param string $template Template file, relative to the templates directory.
	 * @param array  $args     Variables made available to the template.
	 * @return string Rendered markup.
	 */
	public static function get( $template, array $args = array() ) {
		return wc_get_template_html( $template, $args, self::THEME_PATH, self::path() );
	}
}
