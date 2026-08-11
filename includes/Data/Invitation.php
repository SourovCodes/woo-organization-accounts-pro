<?php
/**
 * Invitation entity.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Data;

defined( 'ABSPATH' ) || exit;

/**
 * An outstanding invitation to join an organization.
 *
 * The row holds only the SHA-256 of the token. The token itself is generated once,
 * put in the email, and never stored anywhere — so a copy of the database is not a
 * set of keys to other people's organizations.
 */
class Invitation extends Entity {

	/**
	 * Sent and not yet used.
	 */
	const STATUS_PENDING = 'pending';

	/**
	 * Used. An invitation is good exactly once.
	 */
	const STATUS_ACCEPTED = 'accepted';

	/**
	 * Withdrawn by an organization admin before it was used.
	 */
	const STATUS_REVOKED = 'revoked';

	/**
	 * Every storable column and its default.
	 *
	 * @return array Map of column name to default value.
	 */
	public static function defaults() {
		return array(
			'organization_id' => 0,
			'email'           => '',
			'role'            => Member::ROLE_MEMBER,
			'token_hash'      => '',
			'status'          => self::STATUS_PENDING,
			'expires_at'      => null,
			'invited_by'      => 0,
			'date_created'    => null,
		);
	}

	/**
	 * Column types.
	 *
	 * @return array Map of column name to type.
	 */
	public static function casts() {
		return array(
			'organization_id' => 'int',
			'invited_by'      => 'int',
		);
	}

	/**
	 * The organization this invitation is bound to.
	 *
	 * @return int Organization ID.
	 */
	public function get_organization_id() {
		return (int) $this->get( 'organization_id' );
	}

	/**
	 * The email address this invitation is bound to.
	 *
	 * @return string Email address.
	 */
	public function get_email() {
		return (string) $this->get( 'email' );
	}

	/**
	 * The role the invitee will be given.
	 *
	 * @return string One of the Member::ROLE_* constants.
	 */
	public function get_role() {
		return (string) $this->get( 'role' );
	}

	/**
	 * The user who sent the invitation.
	 *
	 * @return int User ID, or 0 when it was not recorded.
	 */
	public function get_invited_by() {
		return (int) $this->get( 'invited_by' );
	}

	/**
	 * The display name of whoever sent the invitation.
	 *
	 * An organization can have several people who may invite, so "who sent this?" is a
	 * real question when somebody opens the list and finds an invitation they did not
	 * send. The column has been recorded since the first release and read by nothing.
	 *
	 * @return string Display name, or an empty string when the sender is unknown or
	 *                their account has since been deleted.
	 */
	public function get_invited_by_name() {
		$user = $this->get_invited_by() > 0 ? get_user_by( 'id', $this->get_invited_by() ) : false;

		return $user instanceof \WP_User ? $user->display_name : '';
	}

	/**
	 * Whether the invitation is past its expiry.
	 *
	 * An invitation with no expiry never expires; the settings screen allows that
	 * deliberately, for shops that would rather revoke by hand.
	 *
	 * @return bool True when it has expired.
	 */
	public function is_expired() {
		$expires = $this->get( 'expires_at' );

		if ( empty( $expires ) ) {
			return false;
		}

		return strtotime( $expires . ' UTC' ) < time();
	}

	/**
	 * Whether the invitation can still be accepted.
	 *
	 * @return bool True when it is pending and unexpired.
	 */
	public function is_acceptable() {
		return self::STATUS_PENDING === (string) $this->get( 'status' ) && ! $this->is_expired();
	}

	/**
	 * The status to show on screen, folding expiry into it.
	 *
	 * Expiry is a fact about the clock rather than a column, so a pending invitation
	 * whose time has passed has to be reported as expired rather than as still open.
	 *
	 * @return string Translated label.
	 */
	public function get_status_label() {
		$status = (string) $this->get( 'status' );

		if ( self::STATUS_PENDING === $status && $this->is_expired() ) {
			return __( 'Expired', 'woo-organization-accounts-pro' );
		}

		$labels = array(
			self::STATUS_PENDING  => __( 'Pending', 'woo-organization-accounts-pro' ),
			self::STATUS_ACCEPTED => __( 'Accepted', 'woo-organization-accounts-pro' ),
			self::STATUS_REVOKED  => __( 'Revoked', 'woo-organization-accounts-pro' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}
}
