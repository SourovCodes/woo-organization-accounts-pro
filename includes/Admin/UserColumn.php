<?php
/**
 * The organization, on WordPress's own users screen.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Admin;

use WooOrgAccounts\Data\LocationRepository;
use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Data\MemberRepository;
use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Data\OrganizationRepository;
use WooOrgAccounts\Labels;
use WooOrgAccounts\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * Makes a user's organization visible, and findable, where a shop actually looks for people.
 *
 * On a shop whose customers are all organizations, **every account on this screen belongs to
 * one** and the screen said so nowhere. Searching it for a company name returned "no users
 * found", which is correct — the name is in this plugin's table and `WP_User_Query` searches
 * `wp_users` — and useless, because the person searching has a company on the phone and a
 * name they were given, and no reason to know which of the two WordPress keeps where.
 *
 * Three things follow from that, and the search is the one that needed care.
 *
 * **`WP_User_Query` has no seam for an OR across another table.** `user_search_columns`
 * filters a list of `wp_users` columns and reaches nothing else; `include` replaces the
 * search rather than widening it, so resolving the term to user IDs and passing them as
 * `include` would return the organization's members *instead of* the people whose own name
 * matched, which is a different wrong answer. `pre_user_query` is the only hook that can see
 * the assembled SQL, so the matching IDs are appended to the WHERE as an OR. Every ID goes
 * through `absint()` before it is joined, and the organizations are resolved by
 * `OrganizationRepository::query()`, which prepares its own LIKE.
 *
 * **Filtering is `include`, and an empty result has to be `array( 0 )`.** An empty include
 * list means *no restriction* to `WP_User_Query`, so filtering to an organization with no
 * members would silently list every user on the site — the same empty-means-the-opposite
 * trap as an empty location-access list, in a different table.
 *
 * **The profile panel is read-only.** It reports what the membership grants, resolved rather
 * than stored: the `capabilities` column holds a *diff* against the role's defaults, so an
 * empty one means "whatever the role allows" and not "nothing". Editing stays on the
 * plugin's own member screen, so there is one write path and it is the one with the
 * last-admin guard on it.
 */
class UserColumn {

	/**
	 * The column key.
	 */
	const COLUMN = 'woap_organization';

	/**
	 * The filter's query argument.
	 */
	const FILTER = 'woap_organization_id';

	/**
	 * Memberships for the users on the screen, keyed by user ID.
	 *
	 * @var array|null
	 */
	private $memberships = null;

	/**
	 * Organizations named by those memberships, keyed by ID.
	 *
	 * @var array
	 */
	private $organizations = array();

	/**
	 * Whether this class is running a user query of its own.
	 *
	 * @var bool
	 */
	private $searching = false;

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'manage_users_columns', array( $this, 'add_column' ) );
		add_filter( 'manage_users_custom_column', array( $this, 'render_column' ), 10, 3 );
		add_action( 'restrict_manage_users', array( $this, 'render_filter' ) );
		add_action( 'pre_get_users', array( $this, 'apply_filter' ) );
		add_action( 'show_user_profile', array( $this, 'render_panel' ) );
		add_action( 'edit_user_profile', array( $this, 'render_panel' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Load the stylesheet the panel needs.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( ! in_array( (string) $hook_suffix, array( 'users.php', 'user-edit.php', 'profile.php' ), true ) ) {
			return;
		}

		wp_enqueue_style( 'woap-admin', WOAP_PLUGIN_URL . 'assets/css/admin.css', array(), WOAP_VERSION );
	}

	/**
	 * Add the column, immediately after the role.
	 *
	 * @param array $columns The columns.
	 * @return array The columns, with ours in them.
	 */
	public function add_column( $columns ) {
		$placed = array();

		foreach ( (array) $columns as $key => $label ) {
			$placed[ $key ] = $label;

			if ( 'role' === $key ) {
				$placed[ self::COLUMN ] = Labels::organization();
			}
		}

		if ( ! isset( $placed[ self::COLUMN ] ) ) {
			$placed[ self::COLUMN ] = Labels::organization();
		}

		return $placed;
	}

	/**
	 * Render one cell.
	 *
	 * @param string $output      What has been rendered so far.
	 * @param string $column_name Which column.
	 * @param int    $user_id     Which user.
	 * @return string Markup.
	 */
	public function render_column( $output, $column_name, $user_id ) {
		if ( self::COLUMN !== $column_name ) {
			return $output;
		}

		$member = $this->membership( (int) $user_id );

		if ( ! $member instanceof Member ) {
			return '&mdash;';
		}

		$organization = $this->organization( $member->get_organization_id() );

		if ( ! $organization instanceof Organization ) {
			return '&mdash;';
		}

		return sprintf(
			'<a href="%1$s">%2$s</a><br><span class="woap-status woap-status--%3$s">%4$s</span> <span class="description">%5$s</span>',
			esc_url( Organizations::edit_url( $organization->get_id() ) ),
			esc_html( $organization->get_name() ),
			esc_attr( $organization->get_status() ),
			esc_html( $organization->get_status_label() ),
			esc_html( $member->is_admin() ? Labels::organization_admin() : Labels::member() )
		);
	}

	/**
	 * The organization dropdown above the list.
	 *
	 * @param string $which Top or bottom of the table.
	 * @return void
	 */
	public function render_filter( $which ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$id      = 'woap-organization-filter-' . sanitize_key( (string) $which );
		$current = self::current_filter();

		printf(
			'<label class="screen-reader-text" for="%1$s">%2$s</label>',
			esc_attr( $id ),
			esc_html(
				sprintf(
					/* translators: %s: the singular organization noun for the site's mode, for example "Company". */
					__( 'Filter by %s', 'woo-organization-accounts-pro' ),
					Labels::organization()
				)
			)
		);

		printf( '<select name="%1$s" id="%2$s">', esc_attr( self::FILTER ), esc_attr( $id ) );
		printf(
			'<option value="0">%s</option>',
			esc_html(
				sprintf(
					/* translators: %s: the plural organization noun for the site's mode, for example "Companies". */
					__( 'All %s', 'woo-organization-accounts-pro' ),
					Labels::organizations()
				)
			)
		);

		foreach ( OrganizationRepository::query( array( 'orderby' => 'name' ) ) as $organization ) {
			printf(
				'<option value="%1$d"%2$s>%3$s</option>',
				(int) $organization->get_id(),
				selected( $current, $organization->get_id(), false ),
				esc_html( $organization->get_name() )
			);
		}

		echo '</select> ';

		submit_button( __( 'Filter', 'woo-organization-accounts-pro' ), '', 'woap_filter_action', false );
	}

	/**
	 * Restrict the list to one organization's people.
	 *
	 * @param \WP_User_Query $query The query about to run.
	 * @return void
	 */
	public function apply_filter( $query ) {
		if ( $this->searching || ! $this->is_users_screen() ) {
			return;
		}

		$organization_id = self::current_filter();

		if ( 0 === $organization_id ) {
			$this->widen_search( $query );

			return;
		}

		$user_ids = $this->user_ids_for( array( $organization_id ) );

		/*
		 * Never an empty list: `WP_User_Query` reads that as "no restriction" and would
		 * answer a filter for an organization with no members by listing everybody.
		 */
		$query->set( 'include', empty( $user_ids ) ? array( 0 ) : $user_ids );
	}

	/**
	 * Let a search for an organization find the people on it.
	 *
	 * The result a shop wants is the **union** of two searches: the people whose own name or
	 * address matches, which is what WordPress already does, and the people on an
	 * organization whose name, billing address or tax ID matches, which is what this plugin
	 * knows. Neither alone is the answer — searching a surname must still find the surname.
	 *
	 * There is no hook that ORs a condition into `WP_User_Query` safely. Appending to
	 * `query_where` from `pre_user_query` looks like the obvious way and is wrong: `OR` binds
	 * looser than the `AND`s core has already assembled, so a search made from inside a role
	 * filter breaks straight out of it and lists the organization's members whatever role
	 * they hold. Wrapping the clause in brackets does not fix it, because the bracket goes
	 * around the same expression.
	 *
	 * So the union is resolved to IDs first: WordPress's own search is run as its own query,
	 * with the same term and therefore the same rules, and the two sets of IDs are handed
	 * back as `include`. Every other condition on the screen — role, paging, ordering —
	 * stays on the main query untouched, which is exactly what appending SQL put at risk.
	 * It costs one extra query on a request that only happens when somebody types in the
	 * search box.
	 *
	 * @param \WP_User_Query $query The query about to run.
	 * @return void
	 */
	private function widen_search( $query ) {
		$term = trim( (string) $query->get( 'search' ), '*' );

		if ( '' === $term ) {
			return;
		}

		$organizations = OrganizationRepository::query( array( 'search' => $term ) );

		if ( empty( $organizations ) ) {
			return;
		}

		$ids = array();

		foreach ( $organizations as $organization ) {
			$ids[] = $organization->get_id();
		}

		$matched = $this->user_ids_for( $ids );

		if ( empty( $matched ) ) {
			return;
		}

		$union = array_values( array_unique( array_merge( $this->users_matching( $term ), $matched ) ) );

		$query->set( 'search', '' );
		$query->set( 'include', $union );
	}

	/**
	 * The users WordPress's own search would have found for this term.
	 *
	 * Run as a query of its own so it obeys exactly the rules core applies — which columns a
	 * term is matched against depends on the term, and reproducing that here would be a
	 * second answer to a question WordPress already answers.
	 *
	 * @param string $term What was typed.
	 * @return int[] User IDs.
	 */
	private function users_matching( $term ) {
		// Without this the nested query re-enters pre_get_users and never terminates.
		$this->searching = true;

		$found = get_users(
			array(
				'search' => '*' . $term . '*',
				'fields' => 'ID',
				'number' => 0,
			)
		);

		$this->searching = false;

		return array_map( 'absint', (array) $found );
	}

	/**
	 * The membership panel on a user's profile screen.
	 *
	 * @param \WP_User $user The user being viewed.
	 * @return void
	 */
	public function render_panel( $user ) {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! $user instanceof \WP_User ) {
			return;
		}

		$member = MemberRepository::find_by_user( $user->ID );

		if ( ! $member instanceof Member ) {
			return;
		}

		$organization = OrganizationRepository::find( $member->get_organization_id() );

		if ( ! $organization instanceof Organization ) {
			return;
		}

		printf( '<h2>%s</h2>', esc_html( Labels::organization() ) );

		echo '<table class="form-table woap-user-panel" role="presentation"><tbody>';

		$this->row(
			Labels::organization(),
			sprintf(
				'<a href="%1$s">%2$s</a> <span class="woap-status woap-status--%3$s">%4$s</span>',
				esc_url( Organizations::edit_url( $organization->get_id() ) ),
				esc_html( $organization->get_name() ),
				esc_attr( $organization->get_status() ),
				esc_html( $organization->get_status_label() )
			)
		);

		$roles = Member::roles();

		$this->row(
			__( 'Role', 'woo-organization-accounts-pro' ),
			esc_html( $roles[ $member->get_role() ] ?? $member->get_role() )
		);

		$this->row(
			__( 'Status', 'woo-organization-accounts-pro' ),
			esc_html(
				$member->is_active()
					? __( 'Active', 'woo-organization-accounts-pro' )
					: __( 'Inactive', 'woo-organization-accounts-pro' )
			)
		);

		$this->row( Labels::locations(), $this->access_summary( $member, $organization ) );
		$this->row( __( 'Permissions', 'woo-organization-accounts-pro' ), $this->permission_summary( $member ) );

		$this->row(
			'',
			sprintf(
				'<a href="%1$s" class="button">%2$s</a>',
				esc_url( Members::edit_url( $member->get_id() ) ),
				esc_html__( 'Edit this membership', 'woo-organization-accounts-pro' )
			)
		);

		echo '</tbody></table>';
	}

	/**
	 * Which locations this person may ship to, as an answer rather than as stored.
	 *
	 * @param Member       $member       The membership.
	 * @param Organization $organization The account.
	 * @return string Markup.
	 */
	private function access_summary( Member $member, Organization $organization ) {
		$ids = MemberRepository::location_ids( $member->get_id() );

		if ( empty( $ids ) ) {
			return esc_html(
				sprintf(
					/* translators: %s: the plural location noun for the site's mode, for example "Branches". */
					__( 'All %s', 'woo-organization-accounts-pro' ),
					strtolower( Labels::locations() )
				)
			);
		}

		$names = array();

		foreach ( LocationRepository::for_organization( $organization->get_id() ) as $location ) {
			if ( in_array( $location->get_id(), $ids, true ) ) {
				$names[] = $location->get_name();
			}
		}

		return esc_html( implode( ', ', $names ) );
	}

	/**
	 * What this person may do, resolved from the role and their own overrides.
	 *
	 * @param Member $member The membership.
	 * @return string Markup.
	 */
	private function permission_summary( Member $member ) {
		$resolved = array_merge( Roles::role_capabilities( $member->get_role() ), $member->get_capabilities() );
		$labels   = Roles::labels();
		$held     = array();

		foreach ( $resolved as $capability => $granted ) {
			if ( $granted && isset( $labels[ $capability ] ) ) {
				$held[] = $labels[ $capability ];
			}
		}

		if ( empty( $held ) ) {
			return esc_html__( 'Nothing.', 'woo-organization-accounts-pro' );
		}

		$note = empty( $member->get_capabilities() )
			? esc_html__( 'Follows the role.', 'woo-organization-accounts-pro' )
			: esc_html__( 'Set for this person.', 'woo-organization-accounts-pro' );

		return '<ul><li>' . implode( '</li><li>', array_map( 'esc_html', $held ) ) . '</li></ul>'
			. '<p class="description">' . $note . '</p>';
	}

	/**
	 * One row of the panel.
	 *
	 * @param string $label What it is.
	 * @param string $value Escaped markup.
	 * @return void
	 */
	private function row( $label, $value ) {
		printf(
			'<tr><th scope="row">%1$s</th><td>%2$s</td></tr>',
			esc_html( $label ),
			wp_kses_post( $value )
		);
	}

	/**
	 * Every user ID belonging to any of these organizations.
	 *
	 * @param int[] $organization_ids The organizations.
	 * @return int[] User IDs.
	 */
	private function user_ids_for( array $organization_ids ) {
		$user_ids = array();

		foreach ( MemberRepository::for_organizations( $organization_ids ) as $members ) {
			foreach ( $members as $member ) {
				$user_ids[] = $member->get_user_id();
			}
		}

		return array_values( array_unique( array_filter( $user_ids ) ) );
	}

	/**
	 * The membership of one user, from the page's own batch.
	 *
	 * @param int $user_id The user.
	 * @return Member|null The membership, or null.
	 */
	private function membership( $user_id ) {
		if ( null === $this->memberships ) {
			$this->memberships = array();
		}

		if ( ! array_key_exists( $user_id, $this->memberships ) ) {
			$this->memberships[ $user_id ] = MemberRepository::find_by_user( $user_id );
		}

		return $this->memberships[ $user_id ];
	}

	/**
	 * One organization, remembered for the rest of the page.
	 *
	 * @param int $organization_id The organization.
	 * @return Organization|null The organization, or null.
	 */
	private function organization( $organization_id ) {
		if ( ! array_key_exists( $organization_id, $this->organizations ) ) {
			$this->organizations[ $organization_id ] = OrganizationRepository::find( $organization_id );
		}

		return $this->organizations[ $organization_id ];
	}

	/**
	 * The organization the list is filtered to.
	 *
	 * @return int Organization ID, or 0.
	 */
	public static function current_filter() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Choosing which read-only view of the list to show.
		return isset( $_GET[ self::FILTER ] ) ? absint( wp_unslash( $_GET[ self::FILTER ] ) ) : 0;
	}

	/**
	 * Whether this request is the users list, rather than any other user query.
	 *
	 * `pre_get_users` and `pre_user_query` fire for every `WP_User_Query` on the request,
	 * including ones other plugins run for their own reasons. Widening somebody else's query
	 * with our search term would be a plugin answering a question it was not asked.
	 *
	 * @return bool True on the users screen.
	 */
	private function is_users_screen() {
		if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		return $screen instanceof \WP_Screen && 'users' === $screen->id;
	}
}
