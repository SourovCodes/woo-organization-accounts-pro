<?php
/**
 * The customer import screen.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Admin;

use WooOrgAccounts\Import\Mapping;
use WooOrgAccounts\Import\Report;
use WooOrgAccounts\Import\Result;
use WooOrgAccounts\Import\Run;
use WooOrgAccounts\Import\Storage;
use WooOrgAccounts\Labels;

defined( 'ABSPATH' ) || exit;

/**
 * Brings a shop's existing customers in from a flat CSV export.
 *
 * A shop moving onto this plugin has customers who are not organizations yet: one row
 * per person, with the only evidence of who works together being the address they
 * billed to. The screen walks through the four things somebody has to decide before
 * that becomes six hundred organizations — which column is which, how the rows group,
 * what happens to the accounts that already exist, and whether the answer looks right —
 * and only then writes anything.
 *
 * **The preview is the point of the screen.** Everything else here is a form. The
 * preview runs the importer with its writes switched off, over the whole file, and
 * reports exactly what would happen including every row it would have to skip. An
 * import that turns out to have grouped the shop's customers wrongly is not something
 * an undo button fixes, because by then six hundred WordPress accounts exist and some
 * of them have signed in.
 *
 * **The batches are ordinary nonced POSTs to `admin-post.php`, not AJAX.** The screen
 * is in wp-admin, where `admin-post.php` is the right door — the objection in this
 * plugin's frontend handlers is that WooCommerce loads nothing there, which is only
 * about the frontend. Each batch redirects back to the screen, so progress survives a
 * host that kills a long request, and the run continues without JavaScript by clicking
 * the button the script would have clicked.
 */
class Import {

	/**
	 * Screen slug.
	 */
	const PAGE_SLUG = 'woo-organization-accounts-import';

	/**
	 * Capability required to import customers.
	 *
	 * The same one the rest of the plugin's admin uses. Deliberately not `import`,
	 * which is WordPress's own and which a shop manager does not hold.
	 */
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * Transient prefix notices are parked in between the handler and the redirect.
	 */
	const NOTICE_TRANSIENT = 'woap_import_notice_';

	/**
	 * Register the screen and its handlers.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_woap_import_upload', array( $this, 'handle_upload' ) );
		add_action( 'admin_post_woap_import_configure', array( $this, 'handle_configure' ) );
		add_action( 'admin_post_woap_import_start', array( $this, 'handle_start' ) );
		add_action( 'admin_post_woap_import_run', array( $this, 'handle_run' ) );
		add_action( 'admin_post_woap_import_cancel', array( $this, 'handle_cancel' ) );
		add_action( 'admin_post_woap_import_report', array( $this, 'handle_report' ) );
	}

	/**
	 * Add the screen, and then take it back out of the menu.
	 *
	 * Registered under the WooCommerce menu so that it is capability-checked and
	 * reachable the ordinary way, and removed from the menu itself because a permanent
	 * item for something a shop does once is clutter on every other day. It is linked
	 * from the organizations list, which is where somebody who wants it is already
	 * standing. `remove_submenu_page()` only takes the item out of the menu; the page
	 * stays registered, so the URL still resolves and still checks the capability.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'woocommerce',
			$this->title(),
			$this->title(),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);

		remove_submenu_page( 'woocommerce', self::PAGE_SLUG );
	}

	/**
	 * Load the stylesheet the progress bar and the summary table need.
	 *
	 * The organizations screen enqueues the same file for itself, and its check is on
	 * its own slug — which this screen's slug does not contain. Two screens, two
	 * enqueues; `wp_enqueue_style()` de-duplicates by handle if a future screen ever
	 * shows both.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'woap-admin', WOAP_PLUGIN_URL . 'assets/css/admin.css', array(), WOAP_VERSION );
	}

	/**
	 * The screen's title, in the site's organization mode.
	 *
	 * @return string Title.
	 */
	private function title() {
		return sprintf(
			/* translators: %s: the plural organization noun for the site's mode, for example "Companies". */
			__( 'Import %s', 'woo-organization-accounts-pro' ),
			Labels::organizations()
		);
	}

	/**
	 * The screen's URL.
	 *
	 * @param array $args Extra query arguments.
	 * @return string URL.
	 */
	public static function url( array $args = array() ) {
		return add_query_arg(
			array_merge( array( 'page' => self::PAGE_SLUG ), $args ),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * A nonced URL for one of the screen's actions.
	 *
	 * @param string $action Action name, without its `woap_import_` prefix.
	 * @return string URL.
	 */
	private static function action_url( $action ) {
		return wp_nonce_url(
			add_query_arg( array( 'action' => 'woap_import_' . $action ), admin_url( 'admin-post.php' ) ),
			'woap_import_' . $action
		);
	}

	/**
	 * Render whichever step the import has reached.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to import customers.', 'woo-organization-accounts-pro' ) );
		}

		$state = Run::get();

		echo '<div class="wrap woap-import">';
		printf( '<h1>%s</h1>', esc_html( $this->title() ) );

		$this->render_notices();

		if ( null === $state ) {
			Storage::cleanup();
			$this->render_upload();
		} elseif ( ! empty( $state['finished'] ) ) {
			$this->render_done( $state );
		} elseif ( ! empty( $state['started'] ) ) {
			$this->render_running( $state );
		} else {
			$this->render_configure( $state );
		}

		echo '</div>';
	}

	/**
	 * Show anything the last handler left behind.
	 *
	 * @return void
	 */
	private function render_notices() {
		$key     = self::NOTICE_TRANSIENT . get_current_user_id();
		$notices = get_transient( $key );

		if ( ! is_array( $notices ) ) {
			return;
		}

		delete_transient( $key );

		foreach ( $notices as $notice ) {
			printf(
				'<div class="notice notice-%1$s"><p>%2$s</p></div>',
				esc_attr( (string) ( $notice['type'] ?? 'info' ) ),
				esc_html( (string) ( $notice['message'] ?? '' ) )
			);
		}
	}

	/**
	 * Park a notice for the screen the handler is about to redirect to.
	 *
	 * @param string $message Message.
	 * @param string $type    'success', 'error', 'warning' or 'info'.
	 * @return void
	 */
	private static function notice( $message, $type = 'info' ) {
		$key     = self::NOTICE_TRANSIENT . get_current_user_id();
		$notices = get_transient( $key );

		if ( ! is_array( $notices ) ) {
			$notices = array();
		}

		$notices[] = array(
			'message' => (string) $message,
			'type'    => (string) $type,
		);

		set_transient( $key, $notices, MINUTE_IN_SECONDS );
	}

	/**
	 * Step one: choose a file.
	 *
	 * @return void
	 */
	private function render_upload() {
		echo '<p class="description">';
		printf(
			esc_html(
				/* translators: %s: the plural organization noun for the site's mode, for example "companies". */
				__( 'Upload a customer export from your old shop, one row per customer. Rows that share a company name and a street address become one %s, and each row becomes an account inside it.', 'woo-organization-accounts-pro' )
			),
			esc_html( strtolower( Labels::organization() ) )
		);
		echo '</p>';

		echo '<div class="notice notice-info inline"><p>';
		esc_html_e( 'Nothing is written until you have seen a preview of what will happen, and no email is sent to anybody. Imported accounts are created with a random password: your customers set their own through the shop\'s lost-password form, whenever you choose to tell them.', 'woo-organization-accounts-pro' );
		echo '</p></div>';

		printf( '<form method="post" enctype="multipart/form-data" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( 'woap_import_upload' );
		echo '<input type="hidden" name="action" value="woap_import_upload">';

		echo '<table class="form-table" role="presentation"><tbody><tr>';
		printf( '<th scope="row"><label for="woap_import_file">%s</label></th>', esc_html__( 'CSV file', 'woo-organization-accounts-pro' ) );
		echo '<td><input type="file" name="woap_import_file" id="woap_import_file" accept=".csv,text/csv" required>';
		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: maximum upload size, for example "8 MB". */
					__( 'Comma or semicolon separated, with the column headings on the first line. Up to %s.', 'woo-organization-accounts-pro' ),
					size_format( wp_max_upload_size() )
				)
			)
		);
		echo '</td></tr></tbody></table>';

		submit_button( __( 'Upload and continue', 'woo-organization-accounts-pro' ) );
		echo '</form>';
	}

	/**
	 * Step two: confirm the columns and the options, and preview the result.
	 *
	 * @param array $state The run.
	 * @return void
	 */
	private function render_configure( array $state ) {
		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: file name. 2: number of rows. */
					_n( '%1$s, %2$s customer.', '%1$s, %2$s customers.', (int) $state['total'], 'woo-organization-accounts-pro' ),
					$state['name'],
					number_format_i18n( (int) $state['total'] )
				)
			)
		);

		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( 'woap_import_configure' );
		echo '<input type="hidden" name="action" value="woap_import_configure">';

		$this->render_mapping_table( $state );
		$this->render_options( $state );

		submit_button( __( 'Preview the import', 'woo-organization-accounts-pro' ) );
		echo '</form>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Choosing whether to render a read-only preview of the file already uploaded.
		if ( isset( $_GET['woap_preview'] ) ) {
			$this->render_preview( $state );
		}

		printf(
			'<p><a class="button-link button-link-delete" href="%1$s">%2$s</a></p>',
			esc_url( self::action_url( 'cancel' ) ),
			esc_html__( 'Cancel and delete the uploaded file', 'woo-organization-accounts-pro' )
		);
	}

	/**
	 * The column mapping form.
	 *
	 * @param array $state The run.
	 * @return void
	 */
	private function render_mapping_table( array $state ) {
		$headers = (array) $state['headers'];
		$mapping = (array) $state['mapping'];
		$fields  = Mapping::fields();

		foreach ( Mapping::groups() as $group => $heading ) {
			printf( '<h2>%s</h2>', esc_html( $heading ) );
			echo '<table class="form-table" role="presentation"><tbody>';

			foreach ( $fields as $field => $definition ) {
				if ( $definition['group'] !== $group ) {
					continue;
				}

				$id       = 'woap_map_' . $field;
				$selected = (string) ( $mapping[ $field ] ?? '' );

				echo '<tr>';
				printf(
					'<th scope="row"><label for="%1$s">%2$s%3$s</label></th>',
					esc_attr( $id ),
					esc_html( $definition['label'] ),
					$definition['required'] ? ' <span class="description">' . esc_html__( '(required)', 'woo-organization-accounts-pro' ) . '</span>' : ''
				);
				printf( '<td><select name="woap_mapping[%1$s]" id="%2$s">', esc_attr( $field ), esc_attr( $id ) );
				printf(
					'<option value=""%1$s>%2$s</option>',
					selected( $selected, '', false ),
					esc_html__( '— not in this file —', 'woo-organization-accounts-pro' )
				);

				foreach ( $headers as $header ) {
					printf(
						'<option value="%1$s"%2$s>%3$s</option>',
						esc_attr( $header ),
						selected( $selected, $header, false ),
						esc_html( $header )
					);
				}

				echo '</select></td></tr>';
			}

			echo '</tbody></table>';
		}
	}

	/**
	 * The options form.
	 *
	 * @param array $state The run.
	 * @return void
	 */
	private function render_options( array $state ) {
		$options = wp_parse_args( (array) $state['options'], Run::default_options() );

		printf( '<h2>%s</h2>', esc_html__( 'Options', 'woo-organization-accounts-pro' ) );
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Permissions', 'woo-organization-accounts-pro' ) . '</th><td><fieldset>';

		foreach ( Run::role_modes() as $value => $label ) {
			printf(
				'<label><input type="radio" name="woap_role_mode" value="%1$s"%2$s> %3$s</label><br>',
				esc_attr( $value ),
				checked( $options['role_mode'], $value, false ),
				esc_html( $label )
			);
		}

		echo '</fieldset></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Grouping', 'woo-organization-accounts-pro' ) . '</th><td><fieldset>';
		printf(
			'<label><input type="checkbox" name="woap_ignore_legal_form" value="1"%1$s> %2$s</label>',
			checked( ! empty( $options['ignore_legal_form'] ), true, false ),
			esc_html__( 'Treat "Example" and "Example GmbH" at the same address as one account', 'woo-organization-accounts-pro' )
		);
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Off by default. It merges a company that spelled its own name two ways, and it would merge two different companies whose names differ only by their legal form and who share a building.', 'woo-organization-accounts-pro' )
		);
		echo '</fieldset></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Email', 'woo-organization-accounts-pro' ) . '</th><td><fieldset>';
		printf(
			'<label><input type="checkbox" name="woap_suppress_email" value="1"%1$s> %2$s</label>',
			checked( ! empty( $options['suppress_email'] ), true, false ),
			esc_html__( 'Send no email at all while the import runs', 'woo-organization-accounts-pro' )
		);
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Recommended. This plugin sends nothing of its own either way, but another plugin may be listening for new accounts, and an import is not the moment to find out.', 'woo-organization-accounts-pro' )
		);
		echo '</fieldset></td></tr>';

		echo '</tbody></table>';
	}

	/**
	 * The dry run.
	 *
	 * @param array $state The run.
	 * @return void
	 */
	private function render_preview( array $state ) {
		$missing = Mapping::missing_required( (array) $state['mapping'] );

		if ( ! empty( $missing ) ) {
			printf(
				'<div class="notice notice-error inline"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: comma-separated list of field names. */
						__( 'Nothing can be imported until these columns are chosen: %s.', 'woo-organization-accounts-pro' ),
						implode( ', ', $missing )
					)
				)
			);

			return;
		}

		$summary = Run::preview( $state );

		if ( is_wp_error( $summary ) ) {
			printf( '<div class="notice notice-error inline"><p>%s</p></div>', esc_html( $summary->get_error_message() ) );

			return;
		}

		printf( '<h2>%s</h2>', esc_html__( 'Preview', 'woo-organization-accounts-pro' ) );

		if ( ! empty( $summary['truncated'] ) ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: rows previewed. 2: rows in the file. */
						__( 'This preview covers the first %1$s rows of %2$s. The import itself processes the whole file.', 'woo-organization-accounts-pro' ),
						number_format_i18n( (int) $summary['rows'] ),
						number_format_i18n( (int) $state['total'] )
					)
				)
			);
		}

		$this->render_summary( $summary );
		$this->render_problems( $summary );

		if ( ! empty( $summary['issues'] ) ) {
			$this->render_issues( $summary );
		}

		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( 'woap_import_start' );
		echo '<input type="hidden" name="action" value="woap_import_start">';
		submit_button(
			sprintf(
				/* translators: %s: number of rows. */
				__( 'Import %s rows', 'woo-organization-accounts-pro' ),
				number_format_i18n( (int) $state['total'] )
			)
		);
		echo '</form>';
	}

	/**
	 * The counters, as a table.
	 *
	 * @param array $summary Preview summary or run state.
	 * @return void
	 */
	private function render_summary( array $summary ) {
		$rows = array(
			sprintf(
				/* translators: %s: the plural organization noun for the site's mode. */
				__( '%s created', 'woo-organization-accounts-pro' ),
				Labels::organizations()
			) => (int) $summary['organizations'],
			sprintf(
				/* translators: %s: the plural location noun for the site's mode. */
				__( '%s created', 'woo-organization-accounts-pro' ),
				Labels::locations()
			) => (int) $summary['locations'],
		);

		foreach ( Result::labels() as $action => $label ) {
			$rows[ $label ] = (int) ( $summary['counts'][ $action ] ?? 0 );
		}

		echo '<table class="widefat striped woap-import__summary" style="max-width:32em"><tbody>';

		foreach ( $rows as $label => $value ) {
			printf(
				'<tr><th scope="row">%1$s</th><td>%2$s</td></tr>',
				esc_html( $label ),
				esc_html( number_format_i18n( $value ) )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * What is wrong with the file, counted by what is wrong rather than by row.
	 *
	 * The most useful thing on the screen, and it exists because of what a real export
	 * looked like: 581 of its 647 rows had no phone number on a shop whose checkout
	 * requires one. Listed by row that is 581 lines to read, and the five genuinely
	 * broken postcodes were somewhere among them. Counted by problem it is two lines,
	 * and the first of them is a shop-wide setting worth reconsidering before importing
	 * at all rather than a pile of records to repair one at a time.
	 *
	 * @param array $summary Preview summary or run state.
	 * @return void
	 */
	private function render_problems( array $summary ) {
		$problems = (array) ( $summary['problems'] ?? array() );

		if ( empty( $problems ) ) {
			return;
		}

		printf( '<h3>%s</h3>', esc_html__( 'What is wrong with the file', 'woo-organization-accounts-pro' ) );
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'None of these stops a row being imported. A required field that is empty is the one worth reading twice: the shop asks for it at checkout, so an account without it cannot buy until somebody fills it in — or until the shop stops requiring it.', 'woo-organization-accounts-pro' )
		);

		echo '<table class="widefat striped" style="max-width:52em"><thead><tr>';
		printf( '<th scope="col">%s</th>', esc_html__( 'Rows', 'woo-organization-accounts-pro' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Problem', 'woo-organization-accounts-pro' ) );
		echo '</tr></thead><tbody>';

		foreach ( $problems as $problem => $count ) {
			printf(
				'<tr><td>%1$s</td><td>%2$s</td></tr>',
				esc_html( number_format_i18n( (int) $count ) ),
				esc_html( (string) $problem )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * The rows somebody has to look at.
	 *
	 * @param array $summary Preview summary.
	 * @return void
	 */
	private function render_issues( array $summary ) {
		$labels = Result::labels();

		printf( '<h3>%s</h3>', esc_html__( 'Rows worth checking', 'woo-organization-accounts-pro' ) );
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Every one of these is still imported. They are listed because something about them was not straightforward, and the report you can download afterwards lists them all.', 'woo-organization-accounts-pro' )
		);

		echo '<table class="widefat striped"><thead><tr>';
		printf( '<th scope="col">%s</th>', esc_html__( 'Row', 'woo-organization-accounts-pro' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Email address', 'woo-organization-accounts-pro' ) );
		printf( '<th scope="col">%s</th>', esc_html( Labels::organization() ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Result', 'woo-organization-accounts-pro' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Notes', 'woo-organization-accounts-pro' ) );
		echo '</tr></thead><tbody>';

		foreach ( $summary['issues'] as $issue ) {
			echo '<tr>';
			printf( '<td>%s</td>', esc_html( number_format_i18n( (int) $issue['row'] ) ) );
			printf( '<td>%s</td>', esc_html( (string) $issue['email'] ) );
			printf( '<td>%s</td>', esc_html( (string) $issue['organization'] ) );
			printf( '<td>%s</td>', esc_html( $labels[ $issue['action'] ] ?? (string) $issue['action'] ) );
			printf( '<td>%s</td>', esc_html( implode( ' · ', (array) $issue['messages'] ) ) );
			echo '</tr>';
		}

		echo '</tbody></table>';

		if ( count( $summary['issues'] ) >= Run::PREVIEW_ISSUES ) {
			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: number of rows listed. */
						__( 'Only the first %s are listed here.', 'woo-organization-accounts-pro' ),
						number_format_i18n( Run::PREVIEW_ISSUES )
					)
				)
			);
		}
	}

	/**
	 * The screen shown while batches run.
	 *
	 * @param array $state The run.
	 * @return void
	 */
	private function render_running( array $state ) {
		$progress = Run::progress( $state );

		printf( '<h2>%s</h2>', esc_html__( 'Importing', 'woo-organization-accounts-pro' ) );
		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: rows done. 2: rows in the file. */
					__( '%1$s of %2$s rows.', 'woo-organization-accounts-pro' ),
					number_format_i18n( (int) $state['processed'] ),
					number_format_i18n( (int) $state['total'] )
				)
			)
		);

		printf(
			'<div class="woap-import__progress"><div class="woap-import__progress-bar" style="width:%1$d%%"></div></div><p class="description">%2$d%%</p>',
			(int) $progress,
			(int) $progress
		);

		printf( '<form method="post" action="%s" id="woap-import-continue">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( 'woap_import_run' );
		echo '<input type="hidden" name="action" value="woap_import_run">';
		submit_button( __( 'Continue', 'woo-organization-accounts-pro' ), 'primary', 'submit', false );
		echo '</form>';

		/*
		 * The script clicks the button the person would otherwise click. Each batch is a
		 * full round trip that ends in a redirect, so with the script blocked the import
		 * still finishes — one button press per batch — rather than not running at all.
		 */
		wp_print_inline_script_tag( 'document.getElementById( "woap-import-continue" ).submit();' );

		printf(
			'<noscript><p class="description">%s</p></noscript>',
			esc_html__( 'JavaScript is switched off, so press Continue once for each batch until the import finishes.', 'woo-organization-accounts-pro' )
		);

		printf(
			'<p><a class="button-link button-link-delete" href="%1$s">%2$s</a></p>',
			esc_url( self::action_url( 'cancel' ) ),
			esc_html__( 'Stop the import', 'woo-organization-accounts-pro' )
		);
	}

	/**
	 * The screen shown when the last batch has run.
	 *
	 * @param array $state The run.
	 * @return void
	 */
	private function render_done( array $state ) {
		printf(
			'<div class="notice notice-success inline"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: number of rows. */
					__( 'Finished. %s rows were read.', 'woo-organization-accounts-pro' ),
					number_format_i18n( (int) $state['processed'] )
				)
			)
		);

		$this->render_summary( $state );
		$this->render_problems( $state );

		if ( (int) $state['flagged'] > 0 ) {
			printf(
				'<p>%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: number of rows. */
						_n(
							'%s row needs a look. The report says which, and why.',
							'%s rows need a look. The report says which, and why.',
							(int) $state['flagged'],
							'woo-organization-accounts-pro'
						),
						number_format_i18n( (int) $state['flagged'] )
					)
				)
			);
		}

		echo '<p>';

		if ( '' !== (string) $state['report'] ) {
			printf(
				'<a class="button button-primary" href="%1$s">%2$s</a> ',
				esc_url( self::action_url( 'report' ) ),
				esc_html__( 'Download the report', 'woo-organization-accounts-pro' )
			);
		}

		printf(
			'<a class="button" href="%1$s">%2$s</a> ',
			esc_url( Organizations::list_url() ),
			esc_html( Labels::organizations() )
		);

		printf(
			'<a class="button" href="%1$s">%2$s</a>',
			esc_url( self::action_url( 'cancel' ) ),
			esc_html__( 'Finish and clear', 'woo-organization-accounts-pro' )
		);

		echo '</p>';

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Download the report before you clear this: it is deleted with the rest of the import, and it is the only record of which row became which account.', 'woo-organization-accounts-pro' )
		);
	}

	/**
	 * Receive the uploaded file and start a run.
	 *
	 * @return void
	 */
	public function handle_upload() {
		check_admin_referer( 'woap_import_upload' );
		$this->require_capability();

		if ( null !== Run::get() ) {
			self::notice( __( 'An import is already under way. Finish or cancel it first.', 'woo-organization-accounts-pro' ), 'error' );
			$this->go_back();
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- An upload is not a string; Storage::receive() validates the parts of it that are used and sanitises the filename.
		$file = isset( $_FILES['woap_import_file'] ) ? (array) $_FILES['woap_import_file'] : array();
		$path = Storage::receive( $file );

		if ( is_wp_error( $path ) ) {
			self::notice( $path->get_error_message(), 'error' );
			$this->go_back();
		}

		$name  = isset( $file['name'] ) ? sanitize_file_name( (string) $file['name'] ) : '';
		$state = Run::start( $path, $name );

		if ( is_wp_error( $state ) ) {
			Storage::delete( $path );
			self::notice( $state->get_error_message(), 'error' );
			$this->go_back();
		}

		Run::save( $state );

		$missing = Mapping::missing_required( $state['mapping'] );

		if ( ! empty( $missing ) ) {
			self::notice(
				sprintf(
					/* translators: %s: comma-separated list of field names. */
					__( 'These columns could not be recognised and have to be chosen by hand: %s.', 'woo-organization-accounts-pro' ),
					implode( ', ', $missing )
				),
				'warning'
			);
		}

		$this->go_back();
	}

	/**
	 * Save the mapping and options, and show the preview.
	 *
	 * @return void
	 */
	public function handle_configure() {
		check_admin_referer( 'woap_import_configure' );
		$this->require_capability();

		$state = Run::get();

		if ( null === $state || ! empty( $state['started'] ) ) {
			$this->go_back();
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is checked against the file's own headings by Mapping::sanitize().
		$submitted = isset( $_POST['woap_mapping'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['woap_mapping'] ) ) : array();

		$state['mapping'] = Mapping::sanitize( $submitted, (array) $state['headers'] );

		$role_mode = isset( $_POST['woap_role_mode'] ) ? sanitize_key( wp_unslash( $_POST['woap_role_mode'] ) ) : '';

		$state['options'] = array(
			'role_mode'         => array_key_exists( $role_mode, Run::role_modes() ) ? $role_mode : 'all_admin',
			'ignore_legal_form' => isset( $_POST['woap_ignore_legal_form'] ),
			'suppress_email'    => isset( $_POST['woap_suppress_email'] ),
		);

		Run::save( $state );

		$this->go_back( array( 'woap_preview' => 1 ) );
	}

	/**
	 * Begin writing.
	 *
	 * @return void
	 */
	public function handle_start() {
		check_admin_referer( 'woap_import_start' );
		$this->require_capability();

		$state = Run::get();

		if ( null === $state || ! empty( $state['started'] ) ) {
			$this->go_back();
		}

		$missing = Mapping::missing_required( (array) $state['mapping'] );

		if ( ! empty( $missing ) ) {
			self::notice(
				sprintf(
					/* translators: %s: comma-separated list of field names. */
					__( 'Nothing can be imported until these columns are chosen: %s.', 'woo-organization-accounts-pro' ),
					implode( ', ', $missing )
				),
				'error'
			);
			$this->go_back();
		}

		$report = Storage::report_path();

		if ( ! is_wp_error( $report ) && Report::start( $report ) ) {
			$state['report'] = $report;
		}

		$state['started'] = time();

		Run::save( $state );

		$this->go_back();
	}

	/**
	 * Process one batch.
	 *
	 * @return void
	 */
	public function handle_run() {
		check_admin_referer( 'woap_import_run' );
		$this->require_capability();

		$state = Run::get();

		if ( null === $state || empty( $state['started'] ) || ! empty( $state['finished'] ) ) {
			$this->go_back();
		}

		$advanced = Run::process_batch( $state );

		if ( is_wp_error( $advanced ) ) {
			self::notice( $advanced->get_error_message(), 'error' );

			$state['finished'] = true;
			Run::save( $state );

			$this->go_back();
		}

		Run::save( $advanced );

		$this->go_back();
	}

	/**
	 * Throw the import away.
	 *
	 * @return void
	 */
	public function handle_cancel() {
		check_admin_referer( 'woap_import_cancel' );
		$this->require_capability();

		Run::clear();

		self::notice( __( 'The import was cleared and its files deleted.', 'woo-organization-accounts-pro' ), 'success' );

		$this->go_back();
	}

	/**
	 * Send the report to the browser.
	 *
	 * Read back through a handler rather than linked, so that a file listing every
	 * customer's account is behind a capability check and a nonce instead of behind a
	 * URL that can be forwarded.
	 *
	 * @return void
	 */
	public function handle_report() {
		check_admin_referer( 'woap_import_report' );
		$this->require_capability();

		$state = Run::get();
		$path  = null === $state ? '' : (string) $state['report'];

		if ( '' === $path || ! Storage::owns( $path ) || ! is_readable( $path ) ) {
			self::notice( __( 'That report is no longer available.', 'woo-organization-accounts-pro' ), 'error' );
			$this->go_back();
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . Report::filename( (string) $state['name'] ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming a file to the browser; WP_Filesystem would read the whole report into memory to do the same thing.
		readfile( $path );

		exit;
	}

	/**
	 * Stop unless the current user may import customers.
	 *
	 * Called after `check_admin_referer()` in each handler, never instead of it: a nonce
	 * says the request came from a form this site rendered, and says nothing about who
	 * is allowed to send it. The nonce check is written out in each handler rather than
	 * folded in here so that it stays visible at the top of every one of them.
	 *
	 * @return void
	 */
	private function require_capability() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to import customers.', 'woo-organization-accounts-pro' ) );
		}
	}

	/**
	 * Return to the screen.
	 *
	 * @param array $args Extra query arguments.
	 * @return void
	 */
	private function go_back( array $args = array() ) {
		wp_safe_redirect( self::url( $args ) );
		exit;
	}
}
