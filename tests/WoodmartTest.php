<?php
/**
 * Woodmart integration tests.
 *
 * The suite cannot run under Woodmart — it is a commercial theme and cannot be
 * installed into the WordPress test library or into CI — so these assert the things
 * that can be checked without it: that the plugin asks for the theme's components by
 * the names the theme actually uses, that it degrades rather than fatals when the
 * theme is absent, and that it has not gone back to hardcoding its own colours.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Tests;

use WooOrgAccounts\Admin\ThemeSettings;
use WooOrgAccounts\Frontend\MyAccount;
use WP_UnitTestCase;

/**
 * The plugin renders as part of Woodmart rather than on top of it.
 */
class WoodmartTest extends WP_UnitTestCase {

	/**
	 * Read one of the plugin's stylesheets.
	 *
	 * @param string $file File name inside assets/css/.
	 * @return string Contents.
	 */
	private function css( $file ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a file this plugin ships.
		return (string) file_get_contents( WOAP_PLUGIN_DIR . 'assets/css/' . $file );
	}

	/**
	 * Every account endpoint has its own navigation icon.
	 *
	 * Woodmart draws each account menu item's icon from `--wd-my-acc-nav-icon` on the
	 * `wd-my-acc-<endpoint>` class it adds, and defines the variable for its own
	 * endpoints only. An endpoint the plugin adds without a glyph here does not fail —
	 * it silently takes the theme's generic fallback, and the menu reads as several
	 * copies of one item.
	 */
	public function testEveryEndpointHasANavigationIcon() {
		$icons = MyAccount::nav_icons();

		foreach ( array_keys( MyAccount::endpoints() ) as $endpoint ) {
			$this->assertArrayHasKey( $endpoint, $icons, $endpoint . ' has no navigation icon.' );
			$this->assertMatchesRegularExpression(
				'/^\\\\[0-9a-f]{4}$/',
				$icons[ $endpoint ],
				$endpoint . ' does not map to a woodmart-font code point.'
			);
		}

		$this->assertSame(
			count( array_unique( $icons ) ),
			count( $icons ),
			'Two endpoints share an icon, which is what the fallback already did.'
		);
	}

	/**
	 * The stylesheets carry no colour of their own beyond the one documented token.
	 *
	 * Every colour has to come from one of Woodmart's custom properties, or the
	 * organization screens stay wp-admin grey while the rest of the shop follows the
	 * theme — and a shop on the dark colour scheme gets grey-on-grey. The exception is
	 * `--woap-danger-color`: Woodmart's Theme Settings define success and warning
	 * notice colours but no error colour, so the plugin declares that one itself.
	 */
	public function testStylesheetsUseThemeTokensForColour() {
		foreach ( array( 'account.css', 'checkout.css' ) as $file ) {
			$css = $this->css( $file );

			$this->assertStringContainsString( 'var(--wd-', $css, $file . ' references no Woodmart token at all.' );

			/*
			 * Two literals are legitimate and both are removed before the check: the
			 * fallback inside a var(), which only applies if the theme stops defining
			 * that token, and the single declaration of the plugin's own danger colour.
			 */
			$stripped = preg_replace( '/var\(\s*--[a-z0-9-]+\s*,[^)]*\)/i', 'var()', $css );
			$stripped = preg_replace( '/--woap-danger-color:[^;]+;/', '', (string) $stripped );

			preg_match_all( '/#[0-9a-fA-F]{3,8}\b/', (string) $stripped, $matches );

			$this->assertSame(
				array(),
				$matches[0],
				$file . ' hardcodes ' . implode( ', ', $matches[0] ) . ' instead of reading a Woodmart token.'
			);
		}
	}

	/**
	 * A button styled to read as a link outranks the theme's rule for every button.
	 *
	 * Woodmart styles every button through
	 * `:is(.btn, .button, button, [type="submit"], [type="button"])`, which takes the
	 * specificity of its most specific argument — a class. A bare `.woap-link-button`
	 * ties with that, and a tie is settled by source order, which this stylesheet
	 * loses: the secondary actions inside the locations and invitations tables all
	 * rendered as full grey 42px-tall buttons. Qualifying the selector with the element
	 * name wins outright and does not depend on enqueue order.
	 */
	public function testTheLinkButtonOutranksTheThemeButtonRule() {
		$css = $this->css( 'account.css' );

		// Comments explain the selectors, so they must not be mistaken for them.
		$rules = (string) preg_replace( '#/\*.*?\*/#s', '', $css );

		preg_match_all( '/([^{}]+)\{/', $rules, $matches );

		foreach ( $matches[1] as $selector ) {
			foreach ( explode( ',', $selector ) as $part ) {
				$part = trim( $part );

				if ( false === strpos( $part, 'woap-link-button' ) ) {
					continue;
				}

				$this->assertStringStartsWith(
					'button.woap-link-button',
					$part,
					'"' . $part . '" ties with the theme\'s button rule and loses on source order.'
				);
			}
		}

		$this->assertStringContainsString(
			'text-decoration: underline !important',
			$css,
			'The theme declares text-decoration: none !important on every button, so the underline needs one too.'
		);
	}

	/**
	 * Woodmart's parts are only ever requested through the one render-time helper.
	 *
	 * Where a part lands in the cascade is decided by when it is asked for, and several
	 * of them tie with the theme's own base.css on specificity. Asked for on
	 * `wp_enqueue_scripts` a part loads first and loses the tie — which is what put the
	 * show-password control underneath the password field instead of inside it. Routing
	 * every request through `Templates::enqueue_theme_parts()` keeps that reasoning in
	 * one documented place instead of at each call site.
	 */
	public function testThemePartsAreOnlyRequestedAtRenderTime() {
		$offenders = array();

		foreach ( $this->plugin_php_files() as $file ) {
			if ( 'includes/Frontend/Templates.php' === $file ) {
				continue;
			}

			if ( false !== strpos( $this->source( $file ), 'woodmart_enqueue_inline_style(' ) ) {
				$offenders[] = $file;
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			implode( ', ', $offenders ) . ' calls woodmart_enqueue_inline_style() directly instead of Templates::enqueue_theme_parts().'
		);

		$this->assertStringNotContainsString(
			'enqueue_theme_parts',
			$this->between( 'includes/Frontend/Registration.php', 'function enqueue_assets', 'function ' ),
			'The parts are requested from wp_enqueue_scripts again, which loads them before the theme.'
		);
	}

	/**
	 * Every PHP file the plugin ships, relative to the plugin directory.
	 *
	 * @return string[] Paths.
	 */
	private function plugin_php_files() {
		$files    = array();
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( WOAP_PLUGIN_DIR . 'includes', \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$files[] = str_replace( WOAP_PLUGIN_DIR, '', $file->getPathname() );
			}
		}

		return $files;
	}

	/**
	 * The slice of a file between a marker and the next occurrence of a delimiter.
	 *
	 * @param string $path  Path relative to the plugin directory.
	 * @param string $from  Marker the slice starts at.
	 * @param string $until Delimiter the slice ends at.
	 * @return string The slice, or an empty string when the marker is absent.
	 */
	private function between( $path, $from, $until ) {
		$source = $this->source( $path );
		$start  = strpos( $source, $from );

		if ( false === $start ) {
			return '';
		}

		$start += strlen( $from );
		$end    = strpos( $source, $until, $start );

		return false === $end ? substr( $source, $start ) : substr( $source, $start, $end - $start );
	}

	/**
	 * The account screens ask Woodmart for the parts they borrow.
	 *
	 * Woodmart splits its stylesheet and loads each part only where it is used, so a
	 * screen borrowing the theme's tables or notices has to enqueue them by name. A
	 * renamed part degrades to an unstyled table rather than an error, which is
	 * exactly the kind of silence a test has to cover.
	 */
	public function testThePartsTheTemplatesRelyOnAreRequested() {
		$account = $this->source( 'includes/Frontend/MyAccount.php' );

		$this->assertStringContainsString( "'woo-mod-shop-table'", $account, 'The account tables no longer ask for the theme\'s table styling.' );
		$this->assertStringContainsString( "'mod-notices-general'", $account );

		$registration = $this->source( 'includes/Frontend/Registration.php' );

		$this->assertStringContainsString( "'woo-mod-login-form'", $registration, 'The registration form no longer asks for the theme\'s login-form styling.' );

		$this->assertStringContainsString(
			'function_exists( \'woodmart_enqueue_inline_style\' )',
			$this->source( 'includes/Frontend/Templates.php' ),
			'The helper no longer guards against Woodmart being absent.'
		);
	}

	/**
	 * The tables the templates render are the ones Woodmart knows how to stack.
	 *
	 * Woodmart hides the header of a `.shop_table_responsive` on a narrow screen and
	 * prints `attr(data-title)` in front of each value instead. A table carrying the
	 * class but not the attributes becomes a column of unlabelled values on a phone,
	 * which is worse than the unstyled table it replaced.
	 */
	public function testResponsiveTablesCarryTheirLabels() {
		$templates = array(
			'templates/myaccount/locations.php',
			'templates/myaccount/members.php',
			'templates/myaccount/invitations.php',
			'templates/myaccount/organization-orders.php',
		);

		foreach ( $templates as $template ) {
			$source = $this->source( $template );

			$this->assertStringContainsString( 'shop_table_responsive', $source, $template . ' is not a Woodmart responsive table.' );
			$this->assertStringContainsString( 'data-title=', $source, $template . ' stacks without labels on a phone.' );
		}
	}

	/**
	 * Nothing fatals when Woodmart is absent.
	 *
	 * `woap_theme_supported` can be filtered true off Woodmart — the suite itself does
	 * exactly that — so every call into the theme is guarded. This is the whole of
	 * that promise: registering and firing the integration with no theme present.
	 */
	public function testTheIntegrationIsSafeWithoutTheTheme() {
		$this->assertFalse( class_exists( \XTS\Admin\Modules\Options::class ), 'Woodmart is unexpectedly loaded.' );

		$settings = new ThemeSettings();
		$settings->register();

		$this->assertNotFalse( has_action( 'init', array( $settings, 'add_settings' ) ) );

		$settings->add_settings();

		$this->assertTrue( true, 'add_settings() returned without the theme present.' );
	}

	/**
	 * The plugin's settings are not given a second home in the theme's option store.
	 *
	 * Woodmart writes its whole settings screen as one `xts-woodmart-options` array,
	 * and both "Reset to default" and the demo importer overwrite that array wholesale.
	 * `organization_mode` renames every organization noun on the site and
	 * `require_approval` decides whether a new account may buy anything; importing a
	 * demo to try a homepage must not quietly rename half the site or reopen the shop.
	 * The theme panel therefore reads the plugin's option and links to the screen that
	 * writes it.
	 */
	public function testTheThemePanelDoesNotStoreTheSettings() {
		$source = $this->source( 'includes/Admin/ThemeSettings.php' );

		foreach ( array( 'organization_mode', 'require_approval', 'invitation_expiry_days' ) as $setting ) {
			$this->assertStringNotContainsString(
				"'id'       => '" . $setting . "'",
				$source,
				$setting . ' is registered as an editable Woodmart field, giving it two stores.'
			);
		}

		$this->assertStringContainsString( "'type'     => 'notice'", $source, 'The panel is no longer read-only.' );
	}

	/**
	 * Read one of the plugin's own files.
	 *
	 * @param string $path Path relative to the plugin directory.
	 * @return string Contents.
	 */
	private function source( $path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a file this plugin ships.
		return (string) file_get_contents( WOAP_PLUGIN_DIR . $path );
	}
}
