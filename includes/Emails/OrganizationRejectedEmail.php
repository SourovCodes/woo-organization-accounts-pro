<?php
/**
 * The rejection email.
 *
 * @package WooOrgAccounts
 */

use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Emails\StatusEmail;

defined( 'ABSPATH' ) || exit;

/**
 * Tells an organization's admins that the shop has turned the account down.
 *
 * Deliberately says nothing about why. The shop knows its own reasons and may not want
 * them in an automatic email; the message points at the shop instead.
 *
 * Declared in the global namespace rather than `WooOrgAccounts\Emails` — see `Emails`
 * for why the class name is URL surface.
 */
class WooOrgAccounts_Organization_Rejected_Email extends StatusEmail {

	/**
	 * Set the email up and hook its trigger.
	 */
	public function __construct() {
		$this->id             = 'woap_organization_rejected';
		$this->customer_email = true;
		$this->title          = __( 'Organization rejected', 'woo-organization-accounts-pro' );
		$this->description    = __( 'Sent to an organization\'s administrators when the shop turns the account down.', 'woo-organization-accounts-pro' );
		$this->template_html  = 'emails/organization-rejected.php';
		$this->template_plain = 'emails/plain/organization-rejected.php';

		add_action( 'woo_org_accounts_organization_status_changed_notification', array( $this, 'trigger' ), 10, 2 );

		parent::__construct();
	}

	/**
	 * Default this email to off.
	 *
	 * Turning somebody down is the shop's decision to communicate, in its own words
	 * and at its own moment. Setting `$this->enabled` in the constructor would not do
	 * it: WC_Email overwrites that from the saved settings, whose default comes from
	 * this field.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		parent::init_form_fields();

		if ( isset( $this->form_fields['enabled'] ) ) {
			$this->form_fields['enabled']['default'] = 'no';
		}
	}

	/**
	 * The status this email reports.
	 *
	 * @return string Status.
	 */
	protected function status() {
		return Organization::STATUS_REJECTED;
	}

	/**
	 * The default subject line.
	 *
	 * @return string Subject.
	 */
	public function get_default_subject() {
		return __( 'About your account for {organization}', 'woo-organization-accounts-pro' );
	}

	/**
	 * The default heading inside the email.
	 *
	 * @return string Heading.
	 */
	public function get_default_heading() {
		return __( 'We could not approve your account', 'woo-organization-accounts-pro' );
	}
}
