<?php
/**
 * The invitation email.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Emails;

use WooOrgAccounts\Data\Invitation;
use WooOrgAccounts\Data\OrganizationRepository;
use WooOrgAccounts\Labels;
use WooOrgAccounts\Members\Invitations;

defined( 'ABSPATH' ) || exit;

/**
 * Sends somebody the one-time link that lets them join an organization.
 *
 * This is the only place the raw token ever exists outside the moment it was
 * generated, which is why the trigger takes it as an argument rather than looking it
 * up: there is nothing to look up. If this email does not go out, the invitation has
 * to be sent again rather than recovered.
 */
class InvitationEmail extends Email {

	/**
	 * The invitation being sent.
	 *
	 * @var Invitation|null
	 */
	protected $invitation = null;

	/**
	 * The link the recipient follows.
	 *
	 * @var string
	 */
	protected $accept_url = '';

	/**
	 * The name of the organization they are being invited to.
	 *
	 * @var string
	 */
	protected $organization_name = '';

	/**
	 * Set the email up and hook its trigger.
	 */
	public function __construct() {
		$this->id             = 'woap_invitation';
		$this->customer_email = true;
		$this->title          = __( 'Organization invitation', 'woo-organization-accounts-pro' );
		$this->description    = __( 'Sent to somebody who has been invited to join an organization account. It carries the one-time join link.', 'woo-organization-accounts-pro' );
		$this->template_html  = 'emails/invitation.php';
		$this->template_plain = 'emails/plain/invitation.php';

		add_action( 'woo_org_accounts_invitation_created_notification', array( $this, 'trigger' ), 10, 2 );

		parent::__construct();
	}

	/**
	 * The default subject line.
	 *
	 * @return string Subject.
	 */
	public function get_default_subject() {
		/* translators: %s: the name of the organization the recipient is invited to. */
		return __( 'You have been invited to join {organization}', 'woo-organization-accounts-pro' );
	}

	/**
	 * The default heading inside the email.
	 *
	 * @return string Heading.
	 */
	public function get_default_heading() {
		return __( 'Join {organization}', 'woo-organization-accounts-pro' );
	}

	/**
	 * Send the invitation.
	 *
	 * @param Invitation $invitation The invitation.
	 * @param string     $token      The raw one-time token.
	 * @return void
	 */
	public function trigger( $invitation, $token ) {
		if ( ! $invitation instanceof Invitation ) {
			return;
		}

		$organization = OrganizationRepository::find( $invitation->get_organization_id() );

		if ( null === $organization ) {
			return;
		}

		$this->setup_locale();

		$this->invitation        = $invitation;
		$this->accept_url        = Invitations::accept_url( $token );
		$this->organization_name = $organization->get_name();
		$this->recipient         = $invitation->get_email();

		$this->placeholders['{organization}'] = $this->organization_name;

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
		$expires = ( $this->invitation instanceof Invitation ) ? $this->invitation->get( 'expires_at' ) : null;

		return array(
			'organization_name' => $this->organization_name,
			'accept_url'        => $this->accept_url,
			'organization_noun' => Labels::organization(),
			'expires'           => empty( $expires )
				? ''
				: date_i18n( get_option( 'date_format' ), strtotime( $expires . ' UTC' ) ),
		);
	}
}
