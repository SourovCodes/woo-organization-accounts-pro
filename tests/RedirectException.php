<?php
/**
 * Thrown in place of a redirect so a test can see where a handler sent the browser.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Tests;

/**
 * A redirect a handler attempted.
 *
 * Every account handler ends in `wp_safe_redirect()` followed by `exit`, which would
 * end the test run rather than the request. Filtering `wp_redirect` to throw this
 * intercepts the redirect before the exit is reached, so the test can assert on both
 * the destination and everything the handler did on the way there.
 */
class RedirectException extends \Exception {

	/**
	 * Where the handler tried to send the browser.
	 *
	 * @var string
	 */
	public $location;

	/**
	 * Record the destination.
	 *
	 * @param string $location Redirect target.
	 */
	public function __construct( $location ) {
		$this->location = (string) $location;

		parent::__construct( 'Redirect to ' . $this->location );
	}
}
