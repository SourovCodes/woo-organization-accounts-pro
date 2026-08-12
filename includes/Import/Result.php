<?php
/**
 * What happened to one row of an import.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Import;

defined( 'ABSPATH' ) || exit;

/**
 * The outcome of importing one row, for the counters and for the report.
 *
 * A preview pass and a real pass both produce these, from the same code, so the
 * numbers somebody approves on screen are the numbers the import goes on to produce.
 */
final class Result {

	/**
	 * A new organization and a new account.
	 */
	const CREATED = 'created';

	/**
	 * A new account inside an organization the import had already seen.
	 */
	const JOINED = 'joined';

	/**
	 * A WordPress user who already existed, attached to an organization.
	 */
	const LINKED = 'linked';

	/**
	 * Nothing to do: the account is already a member of this organization.
	 */
	const SKIPPED = 'skipped';

	/**
	 * The row could not be imported.
	 */
	const FAILED = 'failed';

	/**
	 * The row this describes.
	 *
	 * @var Record
	 */
	private $record;

	/**
	 * One of the class constants.
	 *
	 * @var string
	 */
	private $action = self::FAILED;

	/**
	 * The organization the row ended up in.
	 *
	 * @var int
	 */
	private $organization_id = 0;

	/**
	 * The WordPress user the row ended up as.
	 *
	 * @var int
	 */
	private $user_id = 0;

	/**
	 * How many locations the row added.
	 *
	 * @var int
	 */
	private $locations_added = 0;

	/**
	 * Notes about what was done, beyond the row's own warnings.
	 *
	 * @var string[]
	 */
	private $notes = array();

	/**
	 * Constructor.
	 *
	 * @param Record $record The row.
	 */
	public function __construct( Record $record ) {
		$this->record = $record;
	}

	/**
	 * Every action, with the label the report and the summary use.
	 *
	 * @return array Map of action to translated label.
	 */
	public static function labels() {
		return array(
			self::CREATED => __( 'Created', 'woo-organization-accounts-pro' ),
			self::JOINED  => __( 'Added to an existing account', 'woo-organization-accounts-pro' ),
			self::LINKED  => __( 'Existing user linked', 'woo-organization-accounts-pro' ),
			self::SKIPPED => __( 'Skipped', 'woo-organization-accounts-pro' ),
			self::FAILED  => __( 'Failed', 'woo-organization-accounts-pro' ),
		);
	}

	/**
	 * Record the outcome.
	 *
	 * @param string $action One of the class constants.
	 * @param string $note   Optional explanation.
	 * @return $this
	 */
	public function set_action( $action, $note = '' ) {
		$this->action = (string) $action;

		if ( '' !== (string) $note ) {
			$this->notes[] = (string) $note;
		}

		return $this;
	}

	/**
	 * Add an explanation without changing the outcome.
	 *
	 * @param string $note Note.
	 * @return $this
	 */
	public function add_note( $note ) {
		$this->notes[] = (string) $note;

		return $this;
	}

	/**
	 * Record which organization the row landed in.
	 *
	 * @param int $organization_id Organization ID.
	 * @return $this
	 */
	public function set_organization_id( $organization_id ) {
		$this->organization_id = (int) $organization_id;

		return $this;
	}

	/**
	 * Record which user the row landed as.
	 *
	 * @param int $user_id User ID.
	 * @return $this
	 */
	public function set_user_id( $user_id ) {
		$this->user_id = (int) $user_id;

		return $this;
	}

	/**
	 * Count a location the row added.
	 *
	 * @return $this
	 */
	public function count_location() {
		++$this->locations_added;

		return $this;
	}

	/**
	 * The row this describes.
	 *
	 * @return Record Row.
	 */
	public function record() {
		return $this->record;
	}

	/**
	 * The outcome.
	 *
	 * @return string One of the class constants.
	 */
	public function action() {
		return $this->action;
	}

	/**
	 * The organization the row ended up in.
	 *
	 * @return int Organization ID, or 0.
	 */
	public function organization_id() {
		return $this->organization_id;
	}

	/**
	 * The user the row ended up as.
	 *
	 * @return int User ID, or 0.
	 */
	public function user_id() {
		return $this->user_id;
	}

	/**
	 * How many locations the row added.
	 *
	 * @return int Count.
	 */
	public function locations_added() {
		return $this->locations_added;
	}

	/**
	 * Everything worth telling the shop about this row.
	 *
	 * The row's own warnings and the notes from writing it, in one list, because the
	 * report has one column for both and the distinction is ours rather than theirs.
	 *
	 * @return string[] Messages.
	 */
	public function messages() {
		return array_merge( $this->record->warnings(), $this->notes );
	}
}
