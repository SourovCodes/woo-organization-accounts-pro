<?php
/**
 * Whether this visitor is looking at the shop as an end customer.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Pricing;

use WooOrgAccounts\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The one answer to "is this request in customer view?", and the one place it is turned
 * on and off.
 *
 * This is the `Membership\Context` of the pricing side: the rule lives here and
 * `CustomerPrices` holds the list of places it has to be applied. Keeping them apart is
 * what stops a screen deciding for itself what customer view means.
 *
 * **The state is a session cookie, and it is the same cookie for everybody.** A visitor
 * who has not signed in and a member showing the catalogue across their own counter want
 * exactly the same thing, so there is one mechanism, one thing to test and one way out.
 * It expires with the browser: this is a mode somebody enters to show somebody else the
 * shop, not a preference they set once.
 *
 * **It cannot be stored against the user instead.** Half the people who want it have no
 * user, and a member's own choice must not follow them onto another device — a mode that
 * survived a sign-in somewhere else would hide their own prices with nothing on screen
 * to explain why.
 *
 * **A request carrying the cookie is never cached.** Two versions of every page exist
 * while this feature is on, and they differ in the one respect the shop most cares
 * about; a full-page cache handing the wrong one to the wrong visitor would either show
 * a stranger the trade price or show a customer nothing at all. Neither state is
 * detectable from a test, so the request opts out of caching outright.
 */
final class CustomerView {

	/**
	 * Cookie recording that this visitor is in customer view.
	 *
	 * Prefixed like every other field this plugin defines. WordPress reads its public
	 * query variables out of `$_POST` as readily as out of the URL, and a bare name here
	 * would be the same trap the registration forms carried until 0.6.0.
	 */
	const COOKIE = 'woap_customer_view';

	/**
	 * Field naming the state being asked for, in the form and in the handler.
	 */
	const FIELD = 'woap_customer_view';

	/**
	 * Nonce action covering both directions.
	 */
	const NONCE = 'woap_customer_view';

	/**
	 * Whether the current request is in customer view.
	 *
	 * Answers false the moment the site setting is off, so switching the feature off is
	 * complete: a browser still holding the cookie from yesterday is simply back in the
	 * ordinary shop.
	 *
	 * **Signing in is part of the rule, not merely how the switch is reached.** This is a
	 * retailer's tool — they turn it on to go through the catalogue with somebody standing
	 * at their counter, on their own screen — so a browser with no account behind it has
	 * nothing to show and nothing to hide. Leaving that out would mean a cookie surviving
	 * a sign-out put the public shop into a mode nobody could see the switch for.
	 *
	 * @return bool True when retail prices are what this visitor should see.
	 */
	public static function is_active() {
		if ( ! self::is_offered() || ! is_user_logged_in() ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared against a literal below; nothing is stored or printed.
		return isset( $_COOKIE[ self::COOKIE ] ) && '1' === $_COOKIE[ self::COOKIE ];
	}

	/**
	 * Whether the site offers customer view at all.
	 *
	 * @return bool True when the setting is on.
	 */
	public static function is_offered() {
		return (bool) Settings::get( 'customer_view', false );
	}

	/**
	 * Enter or leave customer view.
	 *
	 * Two writes, and they answer different questions. The cookie header is what the
	 * *next* request reads; `$_COOKIE` is what *this* one reads, because a header a script
	 * has just sent is not readable back out of the request that sent it. The redirect
	 * afterwards is not the only caller — anything asking `is_active()` later in the same
	 * request has to get the new answer, not the old one.
	 *
	 * The header is sent through WooCommerce's own helper, which decides the secure and
	 * host-only flags from the site's URL rather than leaving them to be got wrong here,
	 * and only while there are still headers to send. A call late enough for that to be
	 * untrue cannot change what the next request sees whatever it does; what it can still
	 * do is keep this one consistent, so it does that silently rather than emitting a
	 * notice about a cookie nobody asked for at that point.
	 *
	 * @param bool $active Whether customer view should be on.
	 * @return void
	 */
	public static function set( $active ) {
		if ( ! headers_sent() ) {
			wc_setcookie(
				self::COOKIE,
				$active ? '1' : '',
				$active ? 0 : time() - YEAR_IN_SECONDS,
				is_ssl(),
				true
			);
		}

		if ( $active ) {
			$_COOKIE[ self::COOKIE ] = '1';

			return;
		}

		unset( $_COOKIE[ self::COOKIE ] );
	}

	/**
	 * Why nothing can be bought while the mode is on.
	 *
	 * One string, said the same way wherever somebody meets the refusal — adding to a
	 * cart, on the cart itself, at either checkout. It is the counterpart of
	 * `Membership\Context::purchase_blocked_reason()` and, like it, is a message rather
	 * than a code because every caller shows it and none branches on it.
	 *
	 * @return string Translated message.
	 */
	public static function purchase_blocked_reason() {
		return __( 'Nothing can be ordered while you are viewing retail prices. Leave customer view to order.', 'woo-organization-accounts-pro' );
	}

	/**
	 * The hidden fields a form needs to switch the mode.
	 *
	 * @param bool $active The state the form asks for.
	 * @return string Markup.
	 */
	public static function form_fields( $active ) {
		return sprintf(
			'<input type="hidden" name="%1$s" value="%2$s">%3$s',
			esc_attr( self::FIELD ),
			$active ? '1' : '0',
			wp_nonce_field( self::NONCE, '_wpnonce', true, false )
		);
	}

	/**
	 * Handle a form asking to enter or leave customer view.
	 *
	 * On `template_redirect` rather than `admin-post.php`, which is the rule
	 * `Frontend\AccountHandlers` sets out and its test asserts: WooCommerce decides what
	 * to load from `is_admin()`, so `wc_add_notice()` on an admin-post request has no
	 * session to add a notice to and drops it silently.
	 *
	 * A nonce is safe on a page that is never cached, and both pages this form appears
	 * on are account pages, which WooCommerce already sends no-cache headers for.
	 *
	 * @return void
	 */
	public static function handle_switch() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Deciding whether the request is ours at all; the nonce is checked immediately below.
		if ( ! isset( $_POST[ self::FIELD ] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( isset( $_POST['_wpnonce'] ) ? sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ) : '', self::NONCE ) ) {
			return;
		}

		if ( ! self::is_offered() ) {
			return;
		}

		$wanted = '1' === sanitize_key( wp_unslash( $_POST[ self::FIELD ] ) );

		self::set( $wanted );

		if ( $wanted ) {
			wc_add_notice(
				__( 'You are now viewing recommended retail prices. Nothing can be ordered until you leave customer view.', 'woo-organization-accounts-pro' ),
				'notice'
			);
		} else {
			wc_add_notice(
				__( 'You have left customer view.', 'woo-organization-accounts-pro' ),
				'notice'
			);
		}

		wp_safe_redirect( self::destination( $wanted ) );
		exit;
	}

	/**
	 * Where to send somebody after the switch.
	 *
	 * Entering goes to the shop, because looking at the catalogue is the whole point and
	 * because the account pages — which is where one of the two controls lives — are
	 * refused while the mode is on. Leaving goes back to the page the control was on, so
	 * a member reading a product sees their own price on the same product rather than
	 * being dropped somewhere else and having to find it again.
	 *
	 * **`wp_get_referer()` cannot be used for that, and looks as though it can.** It
	 * returns false whenever the referer matches the current request URI — and these
	 * forms post to their own URL, so it matches every time. Leaving customer view
	 * therefore always fell through to the account page, from every page on the site.
	 * `wp_get_raw_referer()` is the same value without that comparison, and
	 * `wp_validate_redirect()` is what confines it to this host, which is the check
	 * `wp_get_referer()` was doing and the only part of it that was wanted.
	 *
	 * @param bool $active The state just switched to.
	 * @return string URL.
	 */
	public static function destination( $active ) {
		if ( $active ) {
			$shop = wc_get_page_permalink( 'shop' );

			return $shop ? $shop : home_url( '/' );
		}

		$referer = wp_get_raw_referer();
		$back    = $referer ? wp_validate_redirect( $referer, '' ) : '';

		if ( '' !== $back ) {
			return $back;
		}

		$shop = wc_get_page_permalink( 'shop' );

		return $shop ? $shop : home_url( '/' );
	}
}
