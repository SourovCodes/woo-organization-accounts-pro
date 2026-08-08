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
		$emails['WooOrgAccounts_Invitation']           = new InvitationEmail();
		$emails['WooOrgAccounts_OrganizationApproved'] = new OrganizationApprovedEmail();
		$emails['WooOrgAccounts_OrganizationRejected'] = new OrganizationRejectedEmail();
		$emails['WooOrgAccounts_NewOrganization']      = new NewOrganizationEmail();

		return $emails;
	}
}
