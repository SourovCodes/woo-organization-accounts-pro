<?php
/**
 * Email registration.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Hands the plugin's emails to WooCommerce.
 *
 * The actions are added to `woocommerce_email_actions` as well as being hooked in each
 * email class, because that filter is what makes WooCommerce load its email classes at
 * all when one of them fires. Hooking `woo_org_accounts_invitation_created` directly in
 * a constructor would never run on a request where WooCommerce had no other reason to
 * instantiate its emails — which is most requests, including the one that sends an
 * invitation. Registering the action means WooCommerce re-fires it as
 * `…_notification` with the emails loaded, which is what each class listens for.
 *
 * **The four concrete email classes are declared in the global namespace**, the one
 * place in this plugin that departs from PSR-4, and the reason is that a class name is
 * part of this plugin's URL surface whether we like it or not.
 * `Internal\Admin\EmailPreview\EmailPreview` identifies an email by
 * `get_class()` — there is no filter between that and the wire — and WooCommerce's own
 * settings bundle interpolates the result straight into the preview iframe's `src`
 * without encoding it. A namespaced class therefore puts raw backslashes in a query
 * string, which the WordPress-hardening snippets many hosts run reject with a 403
 * before PHP is reached. Nothing on the PHP side can answer a request that never
 * arrives, so the only fix available to a plugin is to not need the character.
 *
 * `$this->id` and the `woocommerce_{$id}_settings` option it keys are untouched by the
 * class name, so these can be renamed without a shop losing what it has configured.
 * `EmailsTest::testNoRegisteredEmailClassNameNeedsUrlEncoding` is the guard.
 */
class Emails {

	/**
	 * The actions that should wake WooCommerce's email system up.
	 *
	 * @return string[] Hook names.
	 */
	public static function actions() {
		return array(
			'woo_org_accounts_invitation_created',
			'woo_org_accounts_organization_status_changed',
			'woo_org_accounts_organization_registered',
		);
	}

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'woocommerce_email_actions', array( $this, 'add_actions' ) );
		add_filter( 'woocommerce_email_classes', array( $this, 'add_classes' ) );
	}

	/**
	 * Tell WooCommerce which of our actions send email.
	 *
	 * @param array $actions Existing actions.
	 * @return array Actions, with ours added.
	 */
	public function add_actions( $actions ) {
		return array_merge( (array) $actions, self::actions() );
	}

	/**
	 * Add the plugin's email classes.
	 *
	 * @param array $emails Existing email classes, keyed by class name.
	 * @return array Emails, with ours added.
	 */
	public function add_classes( $emails ) {
		$emails['WooOrgAccounts_Invitation']           = new \WooOrgAccounts_Invitation_Email();
		$emails['WooOrgAccounts_OrganizationApproved'] = new \WooOrgAccounts_Organization_Approved_Email();
		$emails['WooOrgAccounts_OrganizationRejected'] = new \WooOrgAccounts_Organization_Rejected_Email();
		$emails['WooOrgAccounts_NewOrganization']      = new \WooOrgAccounts_New_Organization_Email();

		return $emails;
	}
}
