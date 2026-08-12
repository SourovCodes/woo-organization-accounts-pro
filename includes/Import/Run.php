<?php
/**
 * One import, from the file arriving to the report being downloaded.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Import;

defined( 'ABSPATH' ) || exit;

/**
 * The state of the import in progress, and the driver that advances it.
 *
 * An import of any size is more work than one request can finish, so it is broken into
 * batches and this is what a batch resumes from: the file, the column mapping, the
 * options, the byte offset the last batch reached and the counts so far.
 *
 * **State lives in an option rather than a transient.** A transient is allowed to
 * vanish — an object cache under memory pressure evicts one whenever it likes — and an
 * import whose state disappeared halfway through is six hundred half-imported
 * customers with no screen left able to say which. It is stored unautoloaded, and
 * deleted when the import finishes or is abandoned.
 *
 * **There is one import at a time.** Two people importing two files into the same
 * organization table would race on the very thing this plugin's grouping depends on:
 * both would look up the same key, both would find nothing, and both would create the
 * organization.
 *
 * The processing lives here rather than on the admin screen so that a batch can be run
 * — and asserted on — without a request.
 */
final class Run {

	/**
	 * Option the state is stored in.
	 */
	const OPTION = 'woo_org_accounts_import';

	/**
	 * Rows per batch, before the `woo_org_accounts_import_batch_size` filter.
	 */
	const BATCH_SIZE = 50;

	/**
	 * Rows a preview reads, before the `woo_org_accounts_import_preview_limit` filter.
	 */
	const PREVIEW_LIMIT = 5000;

	/**
	 * Rows the preview screen lists individually.
	 */
	const PREVIEW_ISSUES = 100;

	/**
	 * The options an import runs under, with the answers a shop gets by not choosing.
	 *
	 * @return array Map of option to default.
	 */
	public static function default_options() {
		return array(

			/*
			 * Every imported account administers its own organization. These were
			 * standalone accounts on the old shop: each of them could change their own
			 * address and place their own orders, and arriving on the new shop able to
			 * do less than before is a downgrade nobody asked for. A shop that would
			 * rather have one responsible admin per organization chooses first_admin.
			 */
			'role_mode'         => 'all_admin',
			'ignore_legal_form' => false,
			'suppress_email'    => true,
		);
	}

	/**
	 * The membership role each imported account takes, with its label.
	 *
	 * @return array Map of option value to translated label.
	 */
	public static function role_modes() {
		return array(
			'all_admin'   => __( 'Every imported account administers its organization', 'woo-organization-accounts-pro' ),
			'first_admin' => __( 'The first account of each organization administers it; the rest are ordinary members', 'woo-organization-accounts-pro' ),
		);
	}

	/**
	 * How many rows a batch processes.
	 *
	 * @return int Rows.
	 */
	public static function batch_size() {
		/**
		 * Filters how many rows one batch of an import processes.
		 *
		 * Lower it on a host with a short execution limit; raise it on one that can
		 * take it, to finish sooner.
		 *
		 * @since 0.7.0
		 *
		 * @param int $size Rows per batch.
		 */
		return max( 1, (int) apply_filters( 'woo_org_accounts_import_batch_size', self::BATCH_SIZE ) );
	}

	/**
	 * How many rows a preview reads before it stops and says so.
	 *
	 * A preview is one request, and it works out every answer the import will — so on a
	 * file large enough it would run out of time and report nothing at all. It stops and
	 * says how far it got instead, which is a preview of most of the file rather than
	 * a screen that failed.
	 *
	 * @return int Rows.
	 */
	public static function preview_limit() {
		/**
		 * Filters how many rows the import preview reads.
		 *
		 * @since 0.7.0
		 *
		 * @param int $limit Rows.
		 */
		return max( 1, (int) apply_filters( 'woo_org_accounts_import_preview_limit', self::PREVIEW_LIMIT ) );
	}

	/**
	 * The import in progress.
	 *
	 * @return array|null State, or null when no import is under way.
	 */
	public static function get() {
		$state = get_option( self::OPTION, null );

		if ( ! is_array( $state ) || ! isset( $state['total'] ) ) {
			return null;
		}

		return $state;
	}

	/**
	 * Store the state.
	 *
	 * @param array $state State.
	 * @return void
	 */
	public static function save( array $state ) {
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Abandon the import and take its files with it.
	 *
	 * The report goes too. It is the only record of which row became which account, so
	 * the finished screen says to download it first — but leaving it behind would mean a
	 * file naming every customer's account outliving the import that made it, with
	 * nothing left on the site pointing at it or able to delete it.
	 *
	 * @return void
	 */
	public static function clear() {
		$state = self::get();

		if ( null !== $state ) {
			Storage::delete( (string) $state['file'] );
			Storage::delete( (string) $state['report'] );
		}

		delete_option( self::OPTION );
	}

	/**
	 * Begin an import from a stored file.
	 *
	 * The row count is taken here, once, so every screen after this can show progress
	 * without reading the file again.
	 *
	 * @param string $path Absolute path to the stored file.
	 * @param string $name The name it was uploaded under.
	 * @return array|\WP_Error The new state, or an error.
	 */
	public static function start( $path, $name ) {
		$csv = Csv::open( $path );

		if ( is_wp_error( $csv ) ) {
			return $csv;
		}

		$headers = $csv->headers();
		$total   = $csv->count_rows();
		$offset  = $csv->first_row_offset();

		$csv->close();

		if ( 0 === $total ) {
			return new \WP_Error(
				'woap_import_empty',
				__( 'That file has column headings but no customers under them.', 'woo-organization-accounts-pro' )
			);
		}

		return array(
			'file'          => $path,
			'name'          => (string) $name,
			'headers'       => $headers,
			'total'         => $total,
			'first_offset'  => $offset,
			'offset'        => $offset,
			'processed'     => 0,
			'mapping'       => Mapping::detect( $headers ),
			'options'       => self::default_options(),
			'counts'        => array_fill_keys( array_keys( Result::labels() ), 0 ),
			'organizations' => 0,
			'locations'     => 0,
			'flagged'       => 0,
			'problems'      => array(),
			'report'        => '',
			'started'       => 0,
			'finished'      => false,
		);
	}

	/**
	 * Work out what the import would do, without doing any of it.
	 *
	 * @param array $state The state.
	 * @return array|\WP_Error Summary, or an error.
	 */
	public static function preview( array $state ) {
		$csv = Csv::open( (string) $state['file'] );

		if ( is_wp_error( $csv ) ) {
			return $csv;
		}

		$csv->seek( (int) $state['first_offset'] );

		$importer = new Importer( (array) $state['options'], true );
		$limit    = self::preview_limit();

		$summary = array(
			'rows'          => 0,
			'counts'        => array_fill_keys( array_keys( Result::labels() ), 0 ),
			'organizations' => 0,
			'locations'     => 0,
			'flagged'       => 0,
			'problems'      => array(),
			'issues'        => array(),
			'truncated'     => false,
		);

		$number = 0;

		while ( $number < $limit ) {
			$row = $csv->next();

			if ( null === $row ) {
				break;
			}

			++$number;

			$record = new Record( $number, $row, (array) $state['mapping'], (array) $state['options'] );
			$result = $importer->import( $record );

			self::count( $summary, $result );

			if ( self::is_flagged( $result ) && count( $summary['issues'] ) < self::PREVIEW_ISSUES ) {
				$summary['issues'][] = array(
					'row'          => $record->number(),
					'email'        => $record->email(),
					'organization' => $record->organization_name(),
					'action'       => $result->action(),
					'messages'     => $result->messages(),
				);
			}
		}

		$csv->close();

		$summary['rows']          = $number;
		$summary['organizations'] = $importer->organizations_created();
		$summary['truncated']     = $number >= $limit && $number < (int) $state['total'];

		return $summary;
	}

	/**
	 * Process the next batch.
	 *
	 * @param array $state The state, advanced in place.
	 * @return array|\WP_Error The advanced state, or an error.
	 */
	public static function process_batch( array $state ) {
		$csv = Csv::open( (string) $state['file'] );

		if ( is_wp_error( $csv ) ) {
			return $csv;
		}

		$csv->seek( (int) $state['offset'] );

		$importer = new Importer( (array) $state['options'] );
		$batch    = self::batch_size();
		$results  = array();
		$done     = 0;

		$suppressing = ! empty( $state['options']['suppress_email'] );

		if ( $suppressing ) {
			add_filter( 'pre_wp_mail', '__return_false', 99 );
		}

		while ( $done < $batch ) {
			$row = $csv->next();

			if ( null === $row ) {
				break;
			}

			++$done;
			++$state['processed'];

			$record = new Record( (int) $state['processed'], $row, (array) $state['mapping'], (array) $state['options'] );
			$result = $importer->import( $record );

			$results[] = $result;

			self::count( $state, $result );
		}

		if ( $suppressing ) {
			remove_filter( 'pre_wp_mail', '__return_false', 99 );
		}

		$state['offset']         = $csv->offset();
		$state['organizations'] += $importer->organizations_created();

		$csv->close();

		Report::append( (string) $state['report'], $results );

		$state['finished'] = $done < $batch || $state['processed'] >= (int) $state['total'];

		/*
		 * The export is deleted the moment it is no longer needed rather than when the
		 * screen is dismissed, because the screen may never be: somebody reads the
		 * summary, closes the tab, and a file with every customer's address in it stays
		 * in the uploads directory until the sweep gets to it. The report stays — it
		 * carries IDs and warnings rather than a copy of the customer list.
		 */
		if ( $state['finished'] ) {
			Storage::delete( (string) $state['file'] );
			$state['file'] = '';
		}

		return $state;
	}

	/**
	 * Add one outcome to a set of counters.
	 *
	 * The tally of problems is the reason this counts warnings as well as outcomes, and
	 * it came straight out of running a real export: 581 of its 647 rows had no phone
	 * number on a shop whose checkout requires one, so a screen listing the rows with
	 * something wrong listed almost every row, and the five genuinely broken postcodes
	 * were somewhere in the middle of it. Counted by message instead, the same file says
	 * two things in two lines — one of which is a shop-wide setting to reconsider before
	 * importing at all, rather than 581 records to go through.
	 *
	 * @param array  $counters Summary or state, changed in place.
	 * @param Result $result   The outcome.
	 * @return void
	 */
	private static function count( array &$counters, Result $result ) {
		$action = $result->action();

		if ( ! isset( $counters['counts'][ $action ] ) ) {
			$counters['counts'][ $action ] = 0;
		}

		++$counters['counts'][ $action ];
		$counters['locations'] += $result->locations_added();

		foreach ( $result->record()->warnings() as $warning ) {
			if ( ! isset( $counters['problems'][ $warning ] ) ) {
				$counters['problems'][ $warning ] = 0;
			}

			++$counters['problems'][ $warning ];
		}

		arsort( $counters['problems'] );

		if ( self::is_flagged( $result ) ) {
			++$counters['flagged'];
		}
	}

	/**
	 * Whether an outcome is one somebody has to look at.
	 *
	 * A row that was created and had nothing wrong with it is not news. Everything else
	 * is: a skip, a failure, or an address WooCommerce would not have accepted.
	 *
	 * @param Result $result The outcome.
	 * @return bool True when the row wants attention.
	 */
	private static function is_flagged( Result $result ) {
		if ( in_array( $result->action(), array( Result::SKIPPED, Result::FAILED, Result::LINKED ), true ) ) {
			return true;
		}

		return array() !== $result->record()->warnings();
	}

	/**
	 * How far through the file the import is, as a percentage.
	 *
	 * @param array $state The state.
	 * @return int Whole percent, 0 to 100.
	 */
	public static function progress( array $state ) {
		$total = max( 1, (int) $state['total'] );

		return (int) min( 100, round( ( (int) $state['processed'] / $total ) * 100 ) );
	}
}
