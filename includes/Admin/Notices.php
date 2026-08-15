<?php
/**
 * Carrying a message, and a rejected submission, across a redirect.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * The handover between a write and the screen that reports it.
 *
 * Every write in wp-admin here posts to `admin-post.php`, which produces no output and ends
 * in a redirect, so a handler has nowhere to say what happened. This is where it says it:
 * one short-lived transient per user, read once and deleted as it is read.
 *
 * **A rejected submission is parked whole, not reduced to its messages.** Losing a
 * fourteen-field form to one mistyped address is not an acceptable way to report an error,
 * and it is the reason this holds `values` at all. The screen refills itself from them and
 * marks the fields that were rejected, so the correction is one keystroke rather than a
 * retype.
 *
 * Per user and short-lived because it is a handover between two requests, not storage: two
 * people working the same queue must not be shown each other's errors, and a message that
 * outlived the redirect it belongs to would appear over an unrelated screen a minute later.
 *
 * `Organizations` had all of this privately, keyed by its own transient. It is here so that
 * the members, locations and invitation screens do not each grow their own copy — and so the
 * whole admin surface reports success and failure the same way.
 */
class Notices {

	/**
	 * Transient prefix. One per user.
	 */
	const TRANSIENT = 'woap_admin_notice_';

	/**
	 * Park a rejected submission, with everything it was carrying.
	 *
	 * @param \WP_Error $errors What was wrong.
	 * @param array     $values What the submission held, keyed by field name.
	 * @return void
	 */
	public static function hold( \WP_Error $errors, array $values = array() ) {
		self::park(
			array(
				'errors' => $errors->errors,
				'values' => $values,
			)
		);
	}

	/**
	 * Park a message to show once the redirect has landed.
	 *
	 * @param string $message What happened.
	 * @return void
	 */
	public static function success( $message ) {
		self::park( array( 'success' => (string) $message ) );
	}

	/**
	 * Park a failure that is not about any one field.
	 *
	 * @param string $message What went wrong.
	 * @return void
	 */
	public static function error( $message ) {
		self::park( array( 'failure' => (string) $message ) );
	}

	/**
	 * Print whatever is waiting, and hand back the parts a form needs.
	 *
	 * Deleted as it is read, so a message appears once rather than on every view of the
	 * screen until it expires.
	 *
	 * @return array {
	 *     @type \WP_Error|null $0 Errors from a rejected submission.
	 *     @type array          $1 What that submission was carrying.
	 * }
	 */
	public static function consume() {
		$parked = get_transient( self::key() );

		if ( ! is_array( $parked ) ) {
			return array( null, array() );
		}

		delete_transient( self::key() );

		if ( ! empty( $parked['success'] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( (string) $parked['success'] )
			);
		}

		if ( ! empty( $parked['failure'] ) ) {
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html( (string) $parked['failure'] )
			);
		}

		if ( empty( $parked['errors'] ) || ! is_array( $parked['errors'] ) ) {
			return array( null, (array) ( $parked['values'] ?? array() ) );
		}

		$errors = new \WP_Error();

		echo '<div class="notice notice-error"><ul>';

		foreach ( $parked['errors'] as $code => $messages ) {
			foreach ( (array) $messages as $message ) {
				$errors->add( $code, $message );

				printf( '<li>%s</li>', wp_kses_post( $message ) );
			}
		}

		echo '</ul></div>';

		return array( $errors, (array) ( $parked['values'] ?? array() ) );
	}

	/**
	 * Print whatever is waiting, discarding the parts only a form needs.
	 *
	 * @return void
	 */
	public static function render() {
		self::consume();
	}

	/**
	 * Forget anything parked.
	 *
	 * Only the test suite needs this; within a request the state belongs to the one
	 * submission being handled.
	 *
	 * @return void
	 */
	public static function flush() {
		delete_transient( self::key() );
	}

	/**
	 * Store something for the next request by this user.
	 *
	 * @param array $parked What to carry across.
	 * @return void
	 */
	private static function park( array $parked ) {
		set_transient( self::key(), $parked, MINUTE_IN_SECONDS );
	}

	/**
	 * This user's transient key.
	 *
	 * @return string The key.
	 */
	private static function key() {
		return self::TRANSIENT . get_current_user_id();
	}
}
