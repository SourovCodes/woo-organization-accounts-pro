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

	/**
	 * Ask Woodmart for the stylesheet parts a screen about to render depends on.
	 *
	 * **Call this while rendering, never from `wp_enqueue_scripts`.** Woodmart splits
	 * its stylesheet into parts and each one is an ordinary `wp_enqueue_style()`, so
	 * where it lands in the cascade is decided by when it is asked for — and several
	 * of these parts tie with the theme's own `base.css` on specificity, leaving source
	 * order to settle it.
	 *
	 * Asking on `wp_enqueue_scripts` puts the part *before* `base.css` and the theme's
	 * own rules then win. That is not hypothetical: `.show-password-input` is
	 * `position: absolute` in `woo-mod-login-form`, and `base.css` matches the same
	 * button through `:is(.btn, .button, button, [type="submit"], [type="button"])`
	 * with `position: relative` at identical specificity. Enqueued too early, the
	 * show-password control drops out of the field it belongs in and sits underneath
	 * it, while the input keeps the padding reserved for it.
	 *
	 * Woodmart's own templates call this from inside the template for the same reason.
	 * Everything asked for at render time is printed through the theme's footer anchor.
	 *
	 * Guarded, because `woap_theme_supported` can be filtered true off Woodmart.
	 *
	 * @param string ...$keys Woodmart CSS part slugs.
	 * @return void
	 */
	public static function enqueue_theme_parts( ...$keys ) {
		if ( ! function_exists( 'woodmart_enqueue_inline_style' ) ) {
			return;
		}

		foreach ( $keys as $key ) {
			woodmart_enqueue_inline_style( $key );
		}
	}
}
