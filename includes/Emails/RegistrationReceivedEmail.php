<?php
/**
 * The "we have your registration" email to the customer.
 *
 * @package WooOrgAccounts
 */

use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Emails\Email;
use WooOrgAccounts\Labels;

defined( 'ABSPATH' ) || exit;

/**
 * Confirms a registration to the person who submitted it.
 *
 * The shop hears about every registration from the new-organization email; until this
 * one, the customer heard nothing until a decision was made, which on a shop that
 * approves by hand can be days. What they read depends on whether the account still
 * has to be approved: "we are reviewing it, and will write again" when it does, "you
 * can start ordering" when the shop approves nobody.
 *
 * Declared in the global namespace rather than `WooOrgAccounts\Emails` — see `Emails`
 * for why the class name is URL surface.
 */
class WooOrgAccounts_Registration_Received_Email extends Email {

	/**
	 * The organization that registered.
	 *
	 * @var Organization|null
	 */
	protected $organization = null;

	/**
	 * The person who registered it.
	 *
	 * @var WP_User|null
	 */
	protected $user = null;

	/**
	 * Set the email up and hook its trigger.
	 */
	public function __construct() {
		$this->id             = 'woap_registration_received';
		$this->customer_email = true;
		$this->title          = __( 'Registration received', 'woo-organization-accounts-pro' );
		$this->description    = __( 'Sent to the person who registered a new organization, confirming that the registration arrived and, if it needs approval, that it is being reviewed.', 'woo-organization-accounts-pro' );
		$this->template_html  = 'emails/registration-received.php';
		$this->template_plain = 'emails/plain/registration-received.php';

		add_action( 'woo_org_accounts_organization_registered_notification', array( $this, 'trigger' ), 10, 2 );

		parent::__construct();
	}

	/**
	 * The default subject line.
	 *
	 * @return string Subject.
	 */
	public function get_default_subject() {
		return __( 'We have received your registration for {organization}', 'woo-organization-accounts-pro' );
	}

	/**
	 * The default heading inside the email.
	 *
	 * @return string Heading.
	 */
	public function get_default_heading() {
		return __( 'Thank you for registering', 'woo-organization-accounts-pro' );
	}

	/**
	 * Send the confirmation.
	 *
	 * @param Organization $organization The new organization.
	 * @param Member       $member       Its first admin, who filled in the form.
	 * @return void
	 */
	public function trigger( $organization, $member ) {
		if ( ! $organization instanceof Organization || ! $member instanceof Member ) {
			return;
		}

		$user = get_user_by( 'id', $member->get_user_id() );

		if ( ! $user instanceof WP_User || ! is_email( $user->user_email ) ) {
			return;
		}

		$this->setup_locale();

		$this->organization = $organization;
		$this->user         = $user;
		$this->recipient    = $user->user_email;

		$this->placeholders['{organization}'] = $organization->get_name();

		if ( $this->is_enabled() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}

		$this->restore_locale();
	}

	/**
	 * The variables the template needs.
	 *
	 * @return array Template variables.
	 */
	protected function template_args() {
		return array(
			'organization_name' => $this->organization instanceof Organization ? $this->organization->get_name() : '',
			'organization_noun' => Labels::organization(),
			'first_name'        => $this->user instanceof WP_User ? $this->user->first_name : '',
			'needs_approval'    => $this->organization instanceof Organization && Organization::STATUS_PENDING === $this->organization->get_status(),
			'account_url'       => wc_get_page_permalink( 'myaccount' ),
			'shop_url'          => wc_get_page_permalink( 'shop' ),
		);
	}
}
