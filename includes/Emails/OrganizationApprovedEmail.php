<?php
/**
 * The approval email.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Emails;

use WooOrgAccounts\Data\Organization;

defined( 'ABSPATH' ) || exit;

/**
 * Tells an organization's admins that it may now order.
 */
class OrganizationApprovedEmail extends StatusEmail {

	/**
	 * Set the email up and hook its trigger.
	 */
	public function __construct() {
		$this->id             = 'woap_organization_approved';
		$this->customer_email = true;
		$this->title          = __( 'Organization approved', 'woo-organization-accounts-pro' );
		$this->description    = __( 'Sent to an organization\'s administrators when the shop approves the account and it can start ordering.', 'woo-organization-accounts-pro' );
		$this->template_html  = 'emails/organization-approved.php';
		$this->template_plain = 'emails/plain/organization-approved.php';

		add_action( 'woo_org_accounts_organization_status_changed_notification', array( $this, 'trigger' ), 10, 2 );

		parent::__construct();
	}

	/**
	 * The status this email reports.
	 *
	 * @return string Status.
	 */
	protected function status() {
		return Organization::STATUS_ACTIVE;
	}

	/**
	 * The default subject line.
	 *
	 * @return string Subject.
	 */
	public function get_default_subject() {
		return __( '{organization} has been approved', 'woo-organization-accounts-pro' );
	}

	/**
	 * The default heading inside the email.
	 *
	 * @return string Heading.
	 */
	public function get_default_heading() {
		return __( 'You can start ordering', 'woo-organization-accounts-pro' );
	}
}
