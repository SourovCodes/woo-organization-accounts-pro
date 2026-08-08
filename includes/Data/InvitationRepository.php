<?php
/**
 * Invitation repository.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Data;

use WooOrgAccounts\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the invitations table.
 */
class InvitationRepository extends Repository {

	/**
	 * The unprefixed table name.
	 *
	 * @return string Table name.
	 */
	protected static function table_name() {
		return Install::INVITATIONS;
	}

	/**
	 * The entity class rows are hydrated into.
	 *
	 * @return string Class name.
	 */
	protected static function entity_class() {
		return Invitation::class;
	}

	/**
	 * Hash a raw invitation token for storage and lookup.
	 *
	 * SHA-256 without a salt on purpose: the token is 32 characters of the alphabet
	 * wp_generate_password() draws from, so it is not guessable and there is nothing
	 * for a rainbow table to find. What the hash buys is that a database read yields
	 * no usable token, and an unsalted digest is what lets the lookup be a UNIQUE key
	 * hit rather than a scan over every row.
	 *
	 * @param string $token Raw token from an invitation link.
	 * @return string Hex digest.
	 */
	public static function hash_token( $token ) {
		return hash( 'sha256', (string) $token );
	}

	/**
	 * The invitation a raw token refers to.
	 *
	 * Returns whatever the token matches, in whatever state — expired, revoked or
	 * already accepted. Deciding what to do about that is the caller's job, and
	 * Invitation::is_acceptable() is how it decides.
	 *
	 * @param string $token Raw token from an invitation link.
	 * @return Invitation|null Invitation, or null when nothing matches.
	 */
	public static function find_by_token( $token ) {
		global $wpdb;

		$token = (string) $token;

		if ( '' === $token ) {
			return null;
		}

		$table = static::table();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from a class constant; the value is a placeholder.
				"SELECT * FROM {$table} WHERE token_hash = %s",
				self::hash_token( $token )
			),
			ARRAY_A
		);

		return static::hydrate( $row );
	}

	/**
	 * One invitation, but only if it belongs to the given organization.
	 *
	 * @param int $invitation_id   Invitation ID.
	 * @param int $organization_id Organization the invitation must belong to.
	 * @return Invitation|null Invitation, or null when it belongs elsewhere.
	 */
	public static function find_for_organization( $invitation_id, $organization_id ) {
		$invitation = self::find( $invitation_id );

		if ( null === $invitation || $invitation->get_organization_id() !== absint( $organization_id ) ) {
			return null;
		}

		return $invitation;
	}

	/**
	 * Every invitation an organization has issued.
	 *
	 * @param int   $organization_id Organization ID.
	 * @param array $args {
	 *     Optional. Query arguments.
	 *
	 *     @type string $status Restrict to one status.
	 * }
	 * @return Invitation[] Invitations, newest first.
	 */
	public static function for_organization( $organization_id, array $args = array() ) {
		global $wpdb;

		$organization_id = absint( $organization_id );

		if ( 0 === $organization_id ) {
			return array();
		}

		$args = wp_parse_args( $args, array( 'status' => '' ) );

		$table   = static::table();
		$clauses = array( 'organization_id = %d' );
		$params  = array( $organization_id );

		if ( ! empty( $args['status'] ) ) {
			$clauses[] = 'status = %s';
			$params[]  = (string) $args['status'];
		}

		$where = implode( ' AND ', $clauses );
		$query = "SELECT * FROM {$table} WHERE {$where} ORDER BY date_created DESC";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $query is assembled from class constants and literal clauses above; its placeholders are bound here.
		$sql = $wpdb->prepare( $query, $params );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
		return static::hydrate_all( $wpdb->get_results( $sql, ARRAY_A ) );
	}

	/**
	 * How many invitations an organization has in one state.
	 *
	 * A count rather than a fetch, because the screens that report "two are still
	 * waiting" have no use for the rows behind the number.
	 *
	 * @param int    $organization_id Organization ID.
	 * @param string $status          Status to count, or an empty string for all of them.
	 * @return int Invitation count.
	 */
	public static function count_for_organization( $organization_id, $status = '' ) {
		global $wpdb;

		$table = static::table();

		if ( '' === (string) $status ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from a class constant; the value is a placeholder.
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE organization_id = %d", absint( $organization_id ) ) );
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from a class constant; every value is a placeholder.
				"SELECT COUNT(*) FROM {$table} WHERE organization_id = %d AND status = %s",
				absint( $organization_id ),
				(string) $status
			)
		);
	}

	/**
	 * The open invitation an organization already has for an email address.
	 *
	 * Used to turn a second invitation to the same person into a re-send rather than
	 * a second row, so revoking "the" invitation revokes the one that works.
	 *
	 * @param int    $organization_id Organization ID.
	 * @param string $email           Email address.
	 * @return Invitation|null Pending invitation, or null when there is none.
	 */
	public static function find_pending_for_email( $organization_id, $email ) {
		global $wpdb;

		$table = static::table();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from a class constant; every value is a placeholder.
				"SELECT * FROM {$table} WHERE organization_id = %d AND email = %s AND status = %s ORDER BY id DESC",
				absint( $organization_id ),
				sanitize_email( $email ),
				Invitation::STATUS_PENDING
			),
			ARRAY_A
		);

		return static::hydrate( $row );
	}

	/**
	 * Delete every invitation belonging to an organization.
	 *
	 * @param int $organization_id Organization ID.
	 * @return int Number of invitations removed.
	 */
	public static function delete_for_organization( $organization_id ) {
		global $wpdb;

		return (int) $wpdb->delete(
			static::table(),
			array( 'organization_id' => absint( $organization_id ) ),
			array( '%d' )
		);
	}
}
