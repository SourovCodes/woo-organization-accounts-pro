<?php
/**
 * The "a new organization registered" email to the shop.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Emails;

use WooOrgAccounts\Admin\Organizations;
use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Labels;

defined( 'ABSPATH' ) || exit;

/**
 * Tells the shop that somebody has registered and is waiting.
 *
 * Without this, approval is a queue nobody is told about: a new organization sits at
 * "pending" until an administrator happens to open the screen, and the customer waits
 * without knowing why.
 */
class NewOrganizationEmail extends Email {

	/**
	 * The organization that registered.
	 *
	 * @var Organization|null
	 */
	protected $organization = null;

	/**
	 * Its first admin.
	 *
	 * @var Member|null
	 */
	protected $member = null;

	/**
	 * Set the email up and hook its trigger.
	 */
	public function __construct() {
		$this->id             = 'woap_new_organization';
		$this->title          = __( 'New organization registered', 'woo-organization-accounts-pro' );
		$this->description    = __( 'Sent to the shop when a new organization registers, so a pending account is not waiting unnoticed.', 'woo-organization-accounts-pro' );
		$this->template_html  = 'emails/new-organization.php';
		$this->template_plain = 'emails/plain/new-organization.php';

		add_action( 'woo_org_accounts_organization_registered_notification', array( $this, 'trigger' ), 10, 2 );

		parent::__construct();

		$this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );
	}

	/**
	 * The default subject line.
	 *
	 * @return string Subject.
	 */
	public function get_default_subject() {
		return __( '[{site_title}] New registration: {organization}', 'woo-organization-accounts-pro' );
	}

	/**
	 * The default heading inside the email.
	 *
	 * @return string Heading.
	 */
	public function get_default_heading() {
		return __( 'New registration', 'woo-organization-accounts-pro' );
	}

	/**
	 * Add the recipient field to the email's settings.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		parent::init_form_fields();

		$fields = $this->form_fields;

		$recipient = array(
			'recipient' => array(
				'title'       => __( 'Recipients', 'woo-organization-accounts-pro' ),
				'type'        => 'text',
				'description' => sprintf(
					/* translators: %s: the site's admin email address. */
					__( 'Comma-separated addresses. Defaults to %s.', 'woo-organization-accounts-pro' ),
					'<code>' . esc_html( get_option( 'admin_email' ) ) . '</code>'
				),
				'placeholder' => '',
				'default'     => '',
				'desc_tip'    => true,
			),
		);

		$this->form_fields = array_merge(
			array_slice( $fields, 0, 1, true ),
			$recipient,
			array_slice( $fields, 1, null, true )
		);
	}

	/**
	 * Send the notification.
	 *
	 * @param Organization $organization The new organization.
	 * @param Member       $member       Its first admin.
	 * @return void
	 */
	public function trigger( $organization, $member ) {
		if ( ! $organization instanceof Organization ) {
			return;
		}

		$this->setup_locale();

		$this->organization = $organization;
		$this->member       = $member instanceof Member ? $member : null;

		$this->placeholders['{organization}'] = $organization->get_name();

		if ( $this->is_enabled() && $this->get_recipient() ) {
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
		$user = ( $this->member instanceof Member ) ? get_user_by( 'id', $this->member->get_user_id() ) : null;

		return array(
			'organization_name' => $this->organization instanceof Organization ? $this->organization->get_name() : '',
			'organization_noun' => Labels::organization(),
			'status_label'      => $this->organization instanceof Organization ? $this->organization->get_status_label() : '',
			'contact_name'      => $user instanceof \WP_User ? $user->display_name : '',
			'contact_email'     => $user instanceof \WP_User ? $user->user_email : '',
			'review_url'        => $this->organization instanceof Organization ? Organizations::edit_url( $this->organization->get_id() ) : '',
		);
	}
}
