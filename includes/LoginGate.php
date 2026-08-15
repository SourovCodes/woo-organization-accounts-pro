<?php
/**
 * Holding the door shut until an organization has been approved.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts;

use WooOrgAccounts\Admin\Settings;
use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Membership\Context;

defined( 'ABSPATH' ) || exit;

/**
 * The optional rule that only a member of an approved organization may be signed in.
 *
 * `Checkout\Gate` already stops an unapproved organization buying anything; this is the
 * stronger setting for a shop that does not want the account usable at all until
 * somebody has looked at it. It is off by default, because letting a pending customer
 * sign in and look around while they wait is a perfectly reasonable shop too.
 *
 * Three things follow from switching it on, and they have to hold together or the rule
 * has a hole in it:
 *
 * - **Signing in is refused**, on the `authenticate` filter, so every door is covered at
 *   once — My Account, wp-login.php, XML-RPC and application passwords all end up there.
 * - **A session that is already open is ended**, because an organization also becomes
 *   unapproved by being suspended or rejected, and a rule that only applies at the
 *   moment of sign-in would leave whoever was already signed in exactly where they were.
 * - **Registration does not sign the new admin in**, since the cookie is set directly
 *   there and never passes through `authenticate`. Handled by `Frontend\Registration`,
 *   which asks this class.
 *
 * Two people are never locked out. Shop staff — anybody holding `manage_woocommerce` —
 * are exempt, because an administrator who joined an organization to test the shop must
 * not be able to lock themselves out of it by suspending that organization. And a user
 * with no membership at all is not this plugin's business: they are an author, a
 * subscriber or the site owner, and this rule is about organization accounts.
 */
final class LoginGate {

	/**
	 * Setting that switches the rule on.
	 */
	const SETTING = 'require_approval_to_sign_in';

	/**
	 * Query argument carrying why a session was ended, to the screen that says so.
	 */
	const QUERY_VAR = 'woap_signed_out';

	/**
	 * Reason code for an organization still waiting to be approved.
	 */
	const REASON_PENDING = 'pending';

	/**
	 * Reason code for an organization that is suspended or rejected.
	 */
	const REASON_INACTIVE = 'inactive';

	/**
	 * Signed in, and waiting: the account is pending but the sign-in gate is off.
	 *
	 * Not a refusal, which is why it is not returned by `reason()` or `reason_for_status()`
	 * and never ends a session. It exists because the *default* configuration —
	 * `require_approval` on, `require_approval_to_sign_in` off — used to say nothing at all:
	 * somebody filled in a twenty-field form, was signed in, and landed on My Account with
	 * no word about what happens next. The screen for saying it already existed and the
	 * common setup never reached it.
	 */
	const REASON_AWAITING = 'awaiting';

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		/*
		 * Last, at priority 100: core's own handlers resolve the credentials into a user
		 * between 20 and 30, and `wp_authenticate_cookie()` at 30 would otherwise be able
		 * to answer after us. Nothing here overrules an earlier refusal — a WP_Error is
		 * passed straight back out.
		 */
		add_filter( 'authenticate', array( $this, 'refuse_authentication' ), 100 );

		// After WooCommerce has its session, so logging out disposes of that too.
		add_action( 'init', array( $this, 'end_open_session' ), 20 );

		add_action( 'template_redirect', array( $this, 'explain_ended_session' ) );
	}

	/**
	 * Whether the rule is switched on.
	 *
	 * @return bool True when only approved organizations may sign in.
	 */
	public static function is_enabled() {
		return (bool) Settings::get( self::SETTING, false );
	}

	/**
	 * Why this user may not be signed in, if they may not be.
	 *
	 * @param int $user_id User to test.
	 * @return string One of the REASON_* codes, or an empty string when they may sign in.
	 */
	public static function reason( $user_id ) {
		$user_id = absint( $user_id );

		if ( 0 === $user_id || ! self::is_enabled() ) {
			return '';
		}

		// Shop staff are never held out of their own shop.
		if ( user_can( $user_id, Settings::CAPABILITY ) ) {
			return '';
		}

		$member = Context::member( $user_id );

		if ( null === $member ) {
			return '';
		}

		$organization = Context::organization_by_id( $member->get_organization_id() );

		if ( null === $organization ) {
			return '';
		}

		return self::reason_for_status( $organization->get_status() );
	}

	/**
	 * The reason code an organization's status amounts to.
	 *
	 * @param string $status One of the Organization STATUS_* constants.
	 * @return string Reason code, or an empty string when the status is no obstacle.
	 */
	public static function reason_for_status( $status ) {
		if ( ! self::is_enabled() || Organization::STATUS_ACTIVE === $status ) {
			return '';
		}

		return Organization::STATUS_PENDING === $status ? self::REASON_PENDING : self::REASON_INACTIVE;
	}

	/**
	 * What to tell somebody who may not sign in.
	 *
	 * The two reasons are told apart because "we are still looking at your registration"
	 * is worth waiting for and "this account is closed" is worth a phone call, and
	 * neither says anything the account holder does not already know about themselves.
	 *
	 * **It is the customer's account that is being reviewed, not their company.** The status
	 * lives on the organization row, and the person reading this is a person who signed up
	 * and cannot get in — telling them their *company* is under review answers a question
	 * they did not ask, about a record they may not know exists. The company is named as
	 * what the account is for, which is the part they can act on if the shop rings them.
	 *
	 * @param string $reason One of the REASON_* codes.
	 * @param string $name   Optional organization name, to say what the account is for.
	 * @return string Translated message, or an empty string for an unknown code.
	 */
	public static function message( $reason, $name = '' ) {
		$name = trim( (string) $name );

		if ( self::REASON_PENDING === $reason ) {
			return '' !== $name
				? sprintf(
					/* translators: 1: the organization noun for the site's mode, for example "Company", 2: the organization's name. */
					__( 'Your account is still being reviewed. You will be able to sign in as soon as it has been approved. (%1$s: %2$s)', 'woo-organization-accounts-pro' ),
					Labels::organization(),
					$name
				)
				: __( 'Your account is still being reviewed. You will be able to sign in as soon as it has been approved.', 'woo-organization-accounts-pro' );
		}

		if ( self::REASON_INACTIVE === $reason ) {
			return '' !== $name
				? sprintf(
					/* translators: 1: the organization noun for the site's mode, for example "Company", 2: the organization's name. */
					__( 'Your account is not active, so you cannot sign in. Please contact the shop. (%1$s: %2$s)', 'woo-organization-accounts-pro' ),
					Labels::organization(),
					$name
				)
				: __( 'Your account is not active, so you cannot sign in. Please contact the shop.', 'woo-organization-accounts-pro' );
		}

		/*
		 * Signed in and waiting. A different sentence from the two above, because they are
		 * refusals and this is not: they may sign in, browse and fill a cart, and the one
		 * thing they cannot do yet is check out.
		 */
		if ( self::REASON_AWAITING === $reason ) {
			return '' !== $name
				? sprintf(
					/* translators: 1: the organization noun for the site's mode, for example "Company", 2: the organization's name. */
					__( 'Your account is being reviewed. You can sign in and look around; you will be able to place orders as soon as it has been approved. (%1$s: %2$s)', 'woo-organization-accounts-pro' ),
					Labels::organization(),
					$name
				)
				: __( 'Your account is being reviewed. You can sign in and look around; you will be able to place orders as soon as it has been approved.', 'woo-organization-accounts-pro' );
		}

		return '';
	}

	/**
	 * Refuse a sign-in by a member of an organization that has not been approved.
	 *
	 * @param \WP_User|\WP_Error|null $user Whoever the credentials resolved to.
	 * @return \WP_User|\WP_Error|null The user, or the refusal.
	 */
	public function refuse_authentication( $user ) {
		if ( ! $user instanceof \WP_User ) {
			return $user;
		}

		$reason = self::reason( $user->ID );

		if ( '' === $reason ) {
			return $user;
		}

		return new \WP_Error( 'woap_awaiting_approval', self::message( $reason ) );
	}

	/**
	 * End a session belonging to an organization that is no longer approved.
	 *
	 * A suspension has to take effect on the next request rather than whenever the
	 * cookie happens to expire, so this is checked per request rather than only at
	 * sign-in. The redirect carries the reason, so the login screen can say what
	 * happened instead of appearing for no visible cause.
	 *
	 * @return void
	 */
	public function end_open_session() {
		if ( ! is_user_logged_in() || ! self::is_enabled() ) {
			return;
		}

		$reason = self::reason( get_current_user_id() );

		if ( '' === $reason ) {
			return;
		}

		wp_logout();

		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		$account = wc_get_page_permalink( 'myaccount' );

		if ( ! is_string( $account ) || '' === $account ) {
			return;
		}

		wp_safe_redirect( add_query_arg( self::QUERY_VAR, $reason, $account ) );
		exit;
	}

	/**
	 * Say why the visitor is looking at a login form.
	 *
	 * Through WooCommerce's own notices rather than markup of our own, so the message
	 * lands where the theme already styles and positions notices on that page.
	 *
	 * @return void
	 */
	public function explain_ended_session() {
		if ( is_user_logged_in() || ! function_exists( 'wc_add_notice' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading an argument this plugin put in its own redirect, to decide which message to print; nothing is written.
		$reason = isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : '';

		$message = self::message( $reason );

		if ( '' === $message ) {
			return;
		}

		wc_add_notice( $message, 'notice' );
	}
}
