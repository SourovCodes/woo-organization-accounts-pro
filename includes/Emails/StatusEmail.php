<?php
/**
 * Emails sent when an organization's status changes.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Emails;

use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Data\MemberRepository;
use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Labels;

defined( 'ABSPATH' ) || exit;

/**
 * The half of an approval or rejection email that both share.
 *
 * Both are sent to the organization's admins, both are triggered by the same status
 * change, and both need the same recipient list — which is not a fixed address but
 * "everyone who administers this organization right now", and so has to be worked out
 * at send time.
 */
abstract class StatusEmail extends Email {

	/**
	 * The organization whose status changed.
	 *
	 * @var Organization|null
	 */
	protected $organization = null;

	/**
	 * The status this email reports.
	 *
	 * @return string One of the Organization::STATUS_* constants.
	 */
	abstract protected function status();

	/**
	 * Send the email if the status change is the one this class reports.
	 *
	 * @param Organization $organization The organization, with its new status.
	 * @param string       $status       The new status.
	 * @return void
	 */
	public function trigger( $organization, $status ) {
		if ( ! $organization instanceof Organization || $this->status() !== $status ) {
			return;
		}

		$recipients = self::admin_emails( $organization );

		if ( empty( $recipients ) ) {
			return;
		}

		$this->setup_locale();

		$this->organization = $organization;
		$this->recipient    = implode( ',', $recipients );

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
			'account_url'       => wc_get_page_permalink( 'myaccount' ),
			'shop_url'          => wc_get_page_permalink( 'shop' ),
		);
	}

	/**
	 * The email addresses of an organization's active admins.
	 *
	 * @param Organization $organization The organization.
	 * @return string[] Email addresses.
	 */
	protected static function admin_emails( Organization $organization ) {
		$emails = array();

		$admins = MemberRepository::for_organization(
			$organization->get_id(),
			array(
				'role'   => Member::ROLE_ADMIN,
				'status' => Member::STATUS_ACTIVE,
			)
		);

		foreach ( $admins as $member ) {
			$user = get_user_by( 'id', $member->get_user_id() );

			if ( $user instanceof \WP_User && is_email( $user->user_email ) ) {
				$emails[] = $user->user_email;
			}
		}

		return $emails;
	}
}
