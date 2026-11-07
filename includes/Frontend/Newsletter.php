<?php
/**
 * Newsletter opt-in on the registration form.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Frontend;

use WooOrgAccounts\Admin\Settings;
use WooOrgAccounts\Data\Organization;

defined( 'ABSPATH' ) || exit;

/**
 * An optional "keep me informed" checkbox for the person registering an organization.
 *
 * Off by default and a setting, because a shop has to have somewhere to send a newsletter
 * before it asks for consent to one. Switched on, the box is never pre-ticked — consent
 * that was not given by a click is not consent — and a tick is recorded twice over:
 *
 * - **On the account**, as the time it was given (`woap_newsletter_opt_in`). That is the
 *   record that survives whatever happens to a newsletter tool later, and it belongs to
 *   the person, not the organization: the box is about who will read the mail.
 * - **As the `woo_org_accounts_newsletter_opt_in` action**, which is how any newsletter
 *   tool hears about it. Noptin is subscribed directly when it is active, with its own
 *   settings — double opt-in included — deciding what happens next.
 *
 * Only the registration form asks. An invitation is somebody joining an organization the
 * shop already knows, and the people on it can be asked by the newsletter tool itself.
 */
class Newsletter {

	/**
	 * The posted checkbox.
	 */
	const FIELD = 'woap_newsletter';

	/**
	 * User meta holding when consent was given.
	 */
	const META = 'woap_newsletter_opt_in';

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'woo_org_accounts_registration_form', array( $this, 'render_field' ) );
		add_action( 'woo_org_accounts_newsletter_opt_in', array( $this, 'subscribe_to_noptin' ) );
	}

	/**
	 * Whether the registration form offers the checkbox.
	 *
	 * @return bool True when switched on in the settings.
	 */
	public static function is_enabled() {
		return (bool) Settings::get( 'newsletter_opt_in', false );
	}

	/**
	 * The checkbox label: the shop's own wording, or the default.
	 *
	 * @return string Label text.
	 */
	public static function label() {
		$label = trim( (string) Settings::get( 'newsletter_opt_in_label', '' ) );

		return '' !== $label ? $label : self::default_label();
	}

	/**
	 * The label used when the shop has not written its own.
	 *
	 * @return string Label text.
	 */
	public static function default_label() {
		return __( 'Keep me informed about new products and offers.', 'woo-organization-accounts-pro' );
	}

	/**
	 * Print the checkbox at the end of the registration form.
	 *
	 * @return void
	 */
	public function render_field() {
		if ( ! self::is_enabled() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only re-ticks the box on a form that came back; the submission itself is verified by the registration handler.
		$checked = ! empty( $_POST[ self::FIELD ] );

		printf(
			'<p class="woocommerce-form-row form-row-wide woap-registration__newsletter"><label class="woocommerce-form__label woocommerce-form__label-for-checkbox"><input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox" name="%1$s" value="1"%2$s> <span>%3$s</span></label></p>',
			esc_attr( self::FIELD ),
			checked( $checked, true, false ),
			esc_html( self::label() )
		);
	}

	/**
	 * Record a tick from a registration that has just succeeded.
	 *
	 * Called by the registration handler once the account exists, so a refused
	 * submission never records consent for an account that was not created.
	 *
	 * @param int          $user_id      The new account.
	 * @param Organization $organization Its organization.
	 * @param bool         $opted_in     Whether the box was ticked.
	 * @return bool True when consent was recorded.
	 */
	public static function record( $user_id, Organization $organization, $opted_in ) {
		if ( ! $opted_in || ! self::is_enabled() || $user_id <= 0 ) {
			return false;
		}

		update_user_meta( $user_id, self::META, current_time( 'mysql', true ) );

		/**
		 * Fires when somebody registering an organization asks for the newsletter.
		 *
		 * @since 0.17.0
		 *
		 * @param int          $user_id      The account that gave consent.
		 * @param Organization $organization Its organization.
		 */
		do_action( 'woo_org_accounts_newsletter_opt_in', $user_id, $organization );

		return true;
	}

	/**
	 * Subscribe the account to Noptin, when Noptin is active.
	 *
	 * @param int $user_id The account that gave consent.
	 * @return void
	 */
	public function subscribe_to_noptin( $user_id ) {
		if ( ! function_exists( 'add_noptin_subscriber' ) ) {
			return;
		}

		$user = get_user_by( 'id', $user_id );

		if ( ! $user instanceof \WP_User ) {
			return;
		}

		add_noptin_subscriber(
			array(
				'email'      => $user->user_email,
				'first_name' => $user->first_name,
				'last_name'  => $user->last_name,
				'source'     => 'woo-organization-accounts-pro',
			)
		);
	}
}
