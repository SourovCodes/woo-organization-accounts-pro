<?php
/**
 * Organizations admin screen tests.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Tests;

use WooOrgAccounts\Admin\Organizations;
use WooOrgAccounts\Data\LocationRepository;
use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Data\MemberRepository;
use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Data\OrganizationRepository;

/**
 * The shop administrator's edit screen, rendered and posted back.
 *
 * The interesting failure here lives between the two halves rather than inside either:
 * the form printed one set of field names and the save read another, so every save
 * wrote an empty name, email, phone and tax ID over what was stored. Each half was
 * self-consistent, which is why nothing that tested them separately noticed.
 */
class AdminOrganizationsTest extends TestCase {

	/**
	 * An organization whose billing address the checkout's own rules accept.
	 *
	 * The screen validates the address exactly as the checkout does, and a billing phone
	 * is required there — one registered through the frontend always carries one.
	 *
	 * @param array $props Column values to override.
	 * @return Organization The saved organization.
	 */
	private function make_billable_organization( array $props = array() ) {
		$organization = $this->make_organization( $props );

		$organization->set_billing_address(
			array_merge( $organization->get_billing_address(), array( 'phone' => '+49 30 123456' ) )
		);

		OrganizationRepository::save( $organization );

		return $organization;
	}

	/**
	 * Render the detail screen for an organization.
	 *
	 * @param int $organization_id Organization to render.
	 * @return string The markup.
	 */
	private function render_detail( $organization_id ) {
		$_GET['organization_id'] = $organization_id;

		ob_start();
		( new Organizations() )->render();

		return (string) ob_get_clean();
	}

	/**
	 * Render the list screen.
	 *
	 * @return string The markup.
	 */
	private function render_list() {
		unset( $_GET['organization_id'] );

		set_current_screen( 'woocommerce_page_' . Organizations::PAGE_SLUG );

		ob_start();
		( new Organizations() )->render();

		return (string) ob_get_clean();
	}

	/**
	 * Every field name a rendered form posts, in the order they appear.
	 *
	 * Submit buttons are left out: a browser sends only the one that was clicked, so the
	 * table's two Apply buttons sharing a name is correct rather than a collision.
	 *
	 * @param string $markup Rendered screen.
	 * @return string[] Field names, repeats included.
	 */
	private function field_names( $markup ) {
		preg_match_all( '/<(?:input|select|textarea)\b[^>]*\bname="([^"]+)"[^>]*>/', $markup, $matches );

		$names = array();

		foreach ( $matches[0] as $index => $tag ) {
			if ( preg_match( '/\btype="(submit|button|image|reset)"/', $tag ) ) {
				continue;
			}

			$names[] = $matches[1][ $index ];
		}

		return $names;
	}

	/**
	 * Read a rendered form back the way a browser would submit it untouched.
	 *
	 * Hidden fields and the nonce included, unticked checkboxes left out, and the
	 * selected option taken from each select — so what this returns is a real
	 * submission of the screen as it stands rather than a hand-written one.
	 *
	 * @param string $markup Rendered screen.
	 * @return array Field name to submitted value.
	 */
	private function form_state( $markup ) {
		$document = new \DOMDocument();

		libxml_use_internal_errors( true );
		$document->loadHTML( '<?xml encoding="utf-8" ?>' . $markup );
		libxml_clear_errors();

		$state = array();

		foreach ( $document->getElementsByTagName( 'input' ) as $input ) {
			$name = $input->getAttribute( 'name' );
			$type = strtolower( $input->getAttribute( 'type' ) );

			if ( '' === $name || 'submit' === $type ) {
				continue;
			}

			if ( in_array( $type, array( 'checkbox', 'radio' ), true ) && ! $input->hasAttribute( 'checked' ) ) {
				continue;
			}

			$state[ $name ] = $input->getAttribute( 'value' );
		}

		foreach ( $document->getElementsByTagName( 'select' ) as $select ) {
			$name = $select->getAttribute( 'name' );

			if ( '' === $name ) {
				continue;
			}

			$state[ $name ] = '';

			foreach ( $select->getElementsByTagName( 'option' ) as $index => $option ) {
				if ( 0 === $index || $option->hasAttribute( 'selected' ) ) {
					$state[ $name ] = $option->getAttribute( 'value' );
				}

				if ( $option->hasAttribute( 'selected' ) ) {
					break;
				}
			}
		}

		return $state;
	}

	/**
	 * Post a submission to the save handler and report where it redirected to.
	 *
	 * A rejected save redirects too, having stored nothing — so it is checked for here
	 * rather than in the callers. Without this, the round trip below would pass on a
	 * submission the handler threw away, which is the one outcome it must not accept.
	 *
	 * @param array $fields Everything the form submits.
	 * @return string Redirect target.
	 */
	private function save( array $fields ) {
		$_POST    = $fields;
		$_REQUEST = $fields;

		$throw = static function ( $location ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Carried to an assertion in a test, never rendered.
			throw new RedirectException( $location );
		};

		add_filter( 'wp_redirect', $throw );

		try {
			( new Organizations() )->handle_save();
		} catch ( RedirectException $redirect ) {
			$this->assertSaveWasAccepted();

			return $redirect->location;
		} finally {
			remove_filter( 'wp_redirect', $throw );
		}

		$this->fail( 'The save handler did not redirect.' );
	}

	/**
	 * Post a submission that is expected to be refused, and return what was parked.
	 *
	 * @param array $fields Everything the form submits.
	 * @return array {
	 *     @type \WP_Error $errors The reasons.
	 *     @type array     $values What the submission tried to store.
	 * }
	 */
	private function save_expecting_refusal( array $fields ) {
		$_POST    = $fields;
		$_REQUEST = $fields;

		$throw = static function ( $location ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Carried to an assertion in a test, never rendered.
			throw new RedirectException( $location );
		};

		add_filter( 'wp_redirect', $throw );

		try {
			( new Organizations() )->handle_save();
		} catch ( RedirectException $redirect ) {
			unset( $redirect );
		} finally {
			remove_filter( 'wp_redirect', $throw );
		}

		$parked = get_transient( Organizations::NOTICE_TRANSIENT . get_current_user_id() );

		$this->assertIsArray( $parked, 'The save was accepted; it was expected to be refused.' );
		$this->assertNotEmpty( $parked['errors'], 'The save was accepted; it was expected to be refused.' );

		$errors = new \WP_Error();

		foreach ( $parked['errors'] as $code => $messages ) {
			foreach ( (array) $messages as $message ) {
				$errors->add( $code, $message );
			}
		}

		return array(
			'errors' => $errors,
			'values' => isset( $parked['values'] ) ? (array) $parked['values'] : array(),
		);
	}

	/**
	 * Fail with the reasons if the last save parked errors instead of storing anything.
	 *
	 * @return void
	 */
	private function assertSaveWasAccepted() {
		$parked = get_transient( Organizations::NOTICE_TRANSIENT . get_current_user_id() );

		if ( empty( $parked['errors'] ) ) {
			return;
		}

		$messages = array();

		foreach ( $parked['errors'] as $code => $errors ) {
			$messages[] = $code . ': ' . implode( ', ', array_map( 'wp_strip_all_tags', (array) $errors ) );
		}

		delete_transient( Organizations::NOTICE_TRANSIENT . get_current_user_id() );

		$this->fail( 'The save was rejected and stored nothing — ' . implode( '; ', $messages ) );
	}

	/**
	 * Submitting the screen untouched leaves the organization exactly as it was.
	 *
	 * This is the invariant rather than a case, because the bug it catches is a field
	 * name the form and the handler disagree about, and any future field can acquire
	 * one. A round trip through the real markup asserts the two halves agree about all
	 * of them at once: whatever the screen prints, posting it straight back is a no-op.
	 */
	public function testSubmittingTheScreenUnchangedStoresTheSameRecord() {
		$this->act_as_shop_manager();

		$organization = $this->make_billable_organization(
			array(
				'name'   => 'Acme Holdings AG',
				'tax_id' => 'DE811234567',
			)
		);

		$before = OrganizationRepository::find( $organization->get_id() )->to_array();

		$this->save( $this->form_state( $this->render_detail( $organization->get_id() ) ) );

		$after = OrganizationRepository::find( $organization->get_id() )->to_array();

		unset( $before['date_modified'], $after['date_modified'] );

		$this->assertSame( $before, $after );
	}

	/**
	 * What is typed into the screen is what gets stored.
	 */
	public function testSavingTheScreenStoresWhatWasTyped() {
		$this->act_as_shop_manager();

		$organization = $this->make_billable_organization();

		$fields = $this->form_state( $this->render_detail( $organization->get_id() ) );

		$fields['woap_name']     = 'Acme Holdings AG';
		$fields['woap_tax_id']   = 'DE999';
		$fields['billing_email'] = 'hello@acme.test';
		$fields['billing_phone'] = '+49 30 999999';

		$this->save( $fields );

		$saved = OrganizationRepository::find( $organization->get_id() );

		$this->assertSame( 'Acme Holdings AG', $saved->get_name() );
		$this->assertSame( 'DE999', $saved->get( 'tax_id' ) );
		$this->assertSame( 'hello@acme.test', $saved->get( 'billing_email' ) );
		$this->assertSame( '+49 30 999999', $saved->get( 'billing_phone' ) );
	}

	/**
	 * No field on the screen is named after one of WordPress's query variables.
	 *
	 * `AccountHandlersTest::testNoFieldNameCollidesWithAWordPressQueryVar()` asserts
	 * this over the frontend templates; this screen builds its markup in PHP, so it sat
	 * outside that sweep and printed a field called `name`.
	 */
	public function testNoFieldOnTheScreenIsNamedAfterAQueryVar() {
		global $wp;

		$this->act_as_shop_manager();

		$organization = $this->make_billable_organization();
		$fields       = $this->form_state( $this->render_detail( $organization->get_id() ) );

		$this->assertNotEmpty( $fields );

		foreach ( array_keys( $fields ) as $field ) {
			$this->assertNotContains(
				$field,
				$wp->public_query_vars,
				sprintf( 'The screen posts a field called "%s", which WordPress reads as a query variable.', $field )
			);
		}
	}

	/**
	 * An empty name is refused rather than stored.
	 *
	 * The name is what every screen, every order and every parcel label calls this
	 * account. `required` on the input is the browser's opinion; the server had none.
	 */
	public function testAnEmptyNameIsRefused() {
		$this->act_as_shop_manager();

		$organization = $this->make_billable_organization( array( 'name' => 'Acme Holdings AG' ) );

		$fields              = $this->form_state( $this->render_detail( $organization->get_id() ) );
		$fields['woap_name'] = '   ';

		$refusal = $this->save_expecting_refusal( $fields );

		$this->assertNotSame( '', $refusal['errors']->get_error_message( 'woap_name' ) );
		$this->assertSame(
			'Acme Holdings AG',
			OrganizationRepository::find( $organization->get_id() )->get_name(),
			'A refused save must leave the stored name alone.'
		);
	}

	/**
	 * An email address that is not one is refused.
	 *
	 * The billing one, which is the only one an organization has: it is what every
	 * order and every order email is addressed to. WooCommerce marks it required, and
	 * `AddressFields::validate()` judges it exactly as the checkout would.
	 */
	public function testAnUnusableEmailAddressIsRefused() {
		$this->act_as_shop_manager();

		$organization = $this->make_billable_organization();

		$fields                  = $this->form_state( $this->render_detail( $organization->get_id() ) );
		$fields['billing_email'] = 'not-an-address';

		$refusal = $this->save_expecting_refusal( $fields );

		$this->assertNotSame( '', $refusal['errors']->get_error_message( 'billing_email' ) );
		$this->assertSame( 'buy@acme.test', OrganizationRepository::find( $organization->get_id() )->get( 'billing_email' ) );
	}

	/**
	 * A phone number is checked the same way the rest of the address is.
	 */
	public function testAnUnusablePhoneNumberIsRefused() {
		$this->act_as_shop_manager();

		$organization = $this->make_billable_organization();

		$fields                  = $this->form_state( $this->render_detail( $organization->get_id() ) );
		$fields['billing_phone'] = 'call the office';

		$refusal = $this->save_expecting_refusal( $fields );

		$this->assertNotSame( '', $refusal['errors']->get_error_message( 'billing_phone' ) );
	}

	/**
	 * A required tax ID is required on this screen too.
	 */
	public function testARequiredTaxIdIsRefusedWhenBlank() {
		$this->set_setting( 'require_tax_id', true );
		$this->act_as_shop_manager();

		$organization = $this->make_billable_organization( array( 'tax_id' => 'DE811234567' ) );

		$fields                = $this->form_state( $this->render_detail( $organization->get_id() ) );
		$fields['woap_tax_id'] = '';

		$refusal = $this->save_expecting_refusal( $fields );

		$this->assertNotSame( '', $refusal['errors']->get_error_message( 'woap_tax_id' ) );
		$this->assertSame( 'DE811234567', OrganizationRepository::find( $organization->get_id() )->get( 'tax_id' ) );
	}

	/**
	 * A status the plugin does not define is refused rather than quietly dropped.
	 *
	 * `OrganizationRepository::set_status()` already declined to store one, but silently
	 * — the screen redirected saying nothing and showed the old status back, which reads
	 * as the save having been ignored rather than refused.
	 */
	public function testAStatusThatDoesNotExistIsRefused() {
		$this->act_as_shop_manager();

		$organization = $this->make_billable_organization( array( 'status' => Organization::STATUS_PENDING ) );

		$fields                = $this->form_state( $this->render_detail( $organization->get_id() ) );
		$fields['woap_status'] = 'vip';

		$refusal = $this->save_expecting_refusal( $fields );

		$this->assertNotSame( '', $refusal['errors']->get_error_message( 'woap_status' ) );
		$this->assertSame( Organization::STATUS_PENDING, OrganizationRepository::find( $organization->get_id() )->get_status() );
	}

	/**
	 * One bad field does not cost the operator everything else they typed.
	 *
	 * The address has been handed back since the screen was written; the detail fields
	 * were re-read from the database, so correcting a postcode silently undid the
	 * rename that was submitted with it.
	 */
	public function testARefusedSaveHandsBackEverythingThatWasTyped() {
		$this->act_as_shop_manager();

		$organization = $this->make_billable_organization();

		$fields                     = $this->form_state( $this->render_detail( $organization->get_id() ) );
		$fields['woap_name']        = 'Acme Holdings AG';
		$fields['woap_tax_id']      = 'DE999';
		$fields['billing_postcode'] = 'not a postcode';

		$refusal = $this->save_expecting_refusal( $fields );

		$this->assertSame( 'Acme Holdings AG', $refusal['values']['woap_name'] );
		$this->assertSame( 'DE999', $refusal['values']['woap_tax_id'] );

		$markup = $this->render_detail( $organization->get_id() );

		$this->assertStringContainsString( 'value="Acme Holdings AG"', $markup, 'The form came back without the name that was typed.' );
		$this->assertStringContainsString( 'value="DE999"', $markup, 'The form came back without the tax ID that was typed.' );
	}

	/**
	 * A rejected field says what is wrong with it, beside it.
	 */
	public function testARefusedFieldIsMarkedWhereItWentWrong() {
		$this->act_as_shop_manager();

		$organization = $this->make_billable_organization();

		$fields              = $this->form_state( $this->render_detail( $organization->get_id() ) );
		$fields['woap_name'] = '';

		$this->save_expecting_refusal( $fields );

		$markup = $this->render_detail( $organization->get_id() );

		$this->assertStringContainsString( 'woap-row--invalid', $markup );
		$this->assertStringContainsString( 'woap-field-error', $markup );
	}

	/**
	 * Changing the status through the form still fires the hook the emails hang off.
	 */
	public function testAStatusChangeThroughTheFormFiresTheHook() {
		$this->act_as_shop_manager();

		$organization = $this->make_billable_organization( array( 'status' => Organization::STATUS_PENDING ) );

		$fired = array();

		add_action(
			'woo_org_accounts_organization_status_changed',
			static function ( $changed, $status, $previous ) use ( &$fired ) {
				$fired[] = array( $changed->get_id(), $status, $previous );
			},
			10,
			3
		);

		$fields                = $this->form_state( $this->render_detail( $organization->get_id() ) );
		$fields['woap_status'] = Organization::STATUS_ACTIVE;

		$this->save( $fields );

		$this->assertCount( 1, $fired );
		$this->assertSame( Organization::STATUS_ACTIVE, $fired[0][1] );
		$this->assertSame( Organization::STATUS_PENDING, $fired[0][2] );
	}

	/**
	 * A save that changes no status fires nothing, so no approval email goes out.
	 */
	public function testAPlainSaveFiresNoStatusHook() {
		$this->act_as_shop_manager();

		$organization = $this->make_billable_organization( array( 'status' => Organization::STATUS_ACTIVE ) );

		$fired = 0;

		add_action(
			'woo_org_accounts_organization_status_changed',
			static function () use ( &$fired ) {
				++$fired;
			}
		);

		$fields              = $this->form_state( $this->render_detail( $organization->get_id() ) );
		$fields['woap_name'] = 'Renamed';

		$this->save( $fields );

		$this->assertSame( 0, $fired );
	}

	/**
	 * The save is refused without the nonce the form carries.
	 */
	public function testSavingNeedsTheNonce() {
		$this->act_as_shop_manager();

		$organization = $this->make_billable_organization();

		$fields              = $this->form_state( $this->render_detail( $organization->get_id() ) );
		$fields['_wpnonce']  = 'not the nonce';
		$fields['woap_name'] = 'Should not be stored';

		$_POST    = $fields;
		$_REQUEST = $fields;

		$this->expectException( \WPDieException::class );

		( new Organizations() )->handle_save();
	}

	/**
	 * Somebody who does not administer the shop cannot save the screen.
	 */
	public function testSavingNeedsTheCapability() {
		$this->act_as_shop_manager();

		$organization = $this->make_billable_organization();
		$fields       = $this->form_state( $this->render_detail( $organization->get_id() ) );

		// The nonce is the one the operator was issued; only the capability is missing.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$_POST    = $fields;
		$_REQUEST = $fields;

		$this->expectException( \WPDieException::class );

		( new Organizations() )->handle_save();
	}

	/**
	 * No two fields on the list form share a name.
	 *
	 * PHP keeps the last of two fields with one name and discards the first, silently.
	 * The list form printed its own `action` and its own `_wpnonce`, and `WP_List_Table`
	 * then printed both again — so the submission arrived routed to `admin_post_approve`
	 * with a nonce issued for something else, and every bulk approve, suspend and reject
	 * did nothing whatsoever. Neither field is visible on the screen, so nothing about
	 * the rendered page looked wrong.
	 */
	public function testTheListFormNamesEveryFieldOnce() {
		$this->act_as_shop_manager();

		$this->make_billable_organization();

		$names = array_filter(
			$this->field_names( $this->render_list() ),
			// A name ending in [] is a set of values, and is meant to repeat.
			static function ( $name ) {
				return '[]' !== substr( $name, -2 );
			}
		);

		$this->assertNotEmpty( $names );
		$this->assertSame(
			array_values( array_unique( $names ) ),
			array_values( $names ),
			'Two fields on the list form share a name, so PHP will discard one of them.'
		);
	}

	/**
	 * A bulk approval approves everything that was ticked, and nothing else.
	 *
	 * Submitted the way the rendered screen submits it — the nonce read out of the
	 * markup rather than made up here — because the nonce the table prints and the one
	 * the handler checks disagreeing is exactly the failure this covers.
	 */
	public function testABulkApprovalAppliesToEverythingTicked() {
		$this->act_as_shop_manager();

		$first  = $this->make_billable_organization( array( 'status' => Organization::STATUS_PENDING ) );
		$second = $this->make_billable_organization( array( 'status' => Organization::STATUS_PENDING ) );
		$other  = $this->make_billable_organization( array( 'status' => Organization::STATUS_PENDING ) );

		$fields = $this->form_state( $this->render_list() );

		$fields['action']           = 'approve';
		$fields['organization_ids'] = array( $first->get_id(), $second->get_id() );

		$this->bulk( $fields );

		$this->assertSame( Organization::STATUS_ACTIVE, OrganizationRepository::find( $first->get_id() )->get_status() );
		$this->assertSame( Organization::STATUS_ACTIVE, OrganizationRepository::find( $second->get_id() )->get_status() );
		$this->assertSame(
			Organization::STATUS_PENDING,
			OrganizationRepository::find( $other->get_id() )->get_status(),
			'An organization that was not ticked must be left alone.'
		);
	}

	/**
	 * The bottom select applies the same way the top one does.
	 */
	public function testTheSecondBulkSelectWorksToo() {
		$this->act_as_shop_manager();

		$organization = $this->make_billable_organization( array( 'status' => Organization::STATUS_ACTIVE ) );

		$fields = $this->form_state( $this->render_list() );

		$fields['action']           = '-1';
		$fields['action2']          = 'suspend';
		$fields['organization_ids'] = array( $organization->get_id() );

		$this->bulk( $fields );

		$this->assertSame( Organization::STATUS_SUSPENDED, OrganizationRepository::find( $organization->get_id() )->get_status() );
	}

	/**
	 * Simply looking at the list is not a submission and is not refused.
	 *
	 * The handler runs on the screen's own `load-` hook now, so it sees every view of
	 * the list. Checking a nonce before deciding whether anything was submitted would
	 * make the screen unreachable.
	 */
	public function testAnOrdinaryViewOfTheListIsNotTreatedAsASubmission() {
		$this->act_as_shop_manager();

		$organization = $this->make_billable_organization( array( 'status' => Organization::STATUS_PENDING ) );

		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();

		( new Organizations() )->handle_bulk_status();

		$this->assertSame( Organization::STATUS_PENDING, OrganizationRepository::find( $organization->get_id() )->get_status() );
	}

	/**
	 * A bulk action without the nonce is refused.
	 */
	public function testABulkActionNeedsItsNonce() {
		$this->act_as_shop_manager();

		$organization = $this->make_billable_organization( array( 'status' => Organization::STATUS_PENDING ) );

		$_GET = array(
			'action'           => 'approve',
			'organization_ids' => array( $organization->get_id() ),
			'_wpnonce'         => 'not the nonce',
		);

		$_REQUEST = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Building the request the handler verifies; the nonce is set above.

		$this->expectException( \WPDieException::class );

		( new Organizations() )->handle_bulk_status();
	}

	/**
	 * An action the screen does not offer changes nothing.
	 */
	public function testAnUnknownBulkActionChangesNothing() {
		$this->act_as_shop_manager();

		$organization = $this->make_billable_organization( array( 'status' => Organization::STATUS_PENDING ) );

		$fields = $this->form_state( $this->render_list() );

		$fields['action']           = 'delete_everything';
		$fields['organization_ids'] = array( $organization->get_id() );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Building the request the handler verifies; the nonce comes from the rendered form.
		$_GET     = array_merge( $_GET, $fields );
		$_REQUEST = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Building the request the handler verifies; the nonce is set above.

		( new Organizations() )->handle_bulk_status();

		$this->assertSame( Organization::STATUS_PENDING, OrganizationRepository::find( $organization->get_id() )->get_status() );
	}

	/**
	 * Acting on a filtered list comes back to that same filtered list.
	 */
	public function testABulkActionReturnsToTheViewItWasRunFrom() {
		$this->act_as_shop_manager();

		$organization = $this->make_billable_organization( array( 'status' => Organization::STATUS_PENDING ) );

		$_GET['status'] = Organization::STATUS_PENDING;

		$fields = $this->form_state( $this->render_list() );

		$fields['action']           = 'approve';
		$fields['organization_ids'] = array( $organization->get_id() );

		$location = $this->bulk( $fields );

		$this->assertStringContainsString( 'status=' . Organization::STATUS_PENDING, $location );
	}

	/**
	 * The row action moves one organization to one status.
	 */
	public function testTheStatusLinkMovesOneOrganization() {
		$this->act_as_shop_manager();

		$organization = $this->make_billable_organization( array( 'status' => Organization::STATUS_PENDING ) );

		$this->assertStringContainsString(
			'_wpnonce',
			Organizations::status_url( $organization->get_id(), Organization::STATUS_ACTIVE ),
			'The link that changes a status must be nonced.'
		);

		$_GET = array(
			'organization_id' => $organization->get_id(),
			'status'          => Organization::STATUS_ACTIVE,
			'_wpnonce'        => wp_create_nonce( 'woap_admin_set_status_' . $organization->get_id() ),
		);

		$_REQUEST = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Building the request the handler verifies; the nonce is set above.

		$this->expectRedirect(
			static function () {
				( new Organizations() )->handle_set_status();
			}
		);

		$this->assertSame( Organization::STATUS_ACTIVE, OrganizationRepository::find( $organization->get_id() )->get_status() );
	}

	/**
	 * That link is refused when its nonce is not the one for that organization.
	 *
	 * The nonce is built from the ID, so a link for one organization must not approve
	 * another — which is what a single shared action name would allow.
	 */
	public function testTheStatusLinkIsBoundToItsOwnOrganization() {
		$this->act_as_shop_manager();

		$mine  = $this->make_billable_organization( array( 'status' => Organization::STATUS_PENDING ) );
		$other = $this->make_billable_organization( array( 'status' => Organization::STATUS_PENDING ) );

		$_GET = array(
			'organization_id' => $other->get_id(),
			'status'          => Organization::STATUS_ACTIVE,
			'_wpnonce'        => wp_create_nonce( 'woap_admin_set_status_' . $mine->get_id() ),
		);

		$_REQUEST = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Building the request the handler verifies; the nonce is set above.

		$this->expectException( \WPDieException::class );

		( new Organizations() )->handle_set_status();
	}

	/**
	 * Deleting an organization takes everything belonging to it and nothing else.
	 */
	public function testDeletingTakesTheWholeAccountAndLeavesTheOrders() {
		$this->act_as_shop_manager();

		$organization = $this->make_billable_organization();
		$member       = $this->make_member( $organization, Member::ROLE_ADMIN );
		$location     = $this->make_location( $organization );

		$order = wc_create_order();
		$order->update_meta_data( '_woap_organization_id', $organization->get_id() );
		$order->save();

		$survivor = $this->make_billable_organization();
		$this->make_location( $survivor );

		$_GET = array(
			'organization_id' => $organization->get_id(),
			'_wpnonce'        => wp_create_nonce( 'woap_admin_delete_' . $organization->get_id() ),
		);

		$_REQUEST = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Building the request the handler verifies; the nonce is set above.

		$this->expectRedirect(
			static function () {
				( new Organizations() )->handle_delete();
			}
		);

		$this->assertNull( OrganizationRepository::find( $organization->get_id() ) );
		$this->assertNull( MemberRepository::find_by_user( $member->get_user_id() ) );
		$this->assertSame( array(), LocationRepository::for_organization( $organization->get_id() ) );

		$this->assertContains(
			'customer',
			( new \WP_User( $member->get_user_id() ) )->roles,
			'Somebody whose organization was deleted keeps an account, as an ordinary customer.'
		);

		$this->assertNotNull(
			wc_get_order( $order->get_id() ),
			'Orders are the shop\'s records and are never deleted with an organization.'
		);

		$this->assertNotNull( OrganizationRepository::find( $survivor->get_id() ) );
		$this->assertCount( 1, LocationRepository::for_organization( $survivor->get_id() ) );

		unset( $location );
	}

	/**
	 * Deleting is refused without its nonce.
	 */
	public function testDeletingNeedsItsNonce() {
		$this->act_as_shop_manager();

		$organization = $this->make_billable_organization();

		$_GET = array(
			'organization_id' => $organization->get_id(),
			'_wpnonce'        => 'not the nonce',
		);

		$_REQUEST = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Building the request the handler verifies; the nonce is set above.

		$this->expectException( \WPDieException::class );

		( new Organizations() )->handle_delete();
	}

	/**
	 * Run a bulk submission and report where it redirected to.
	 *
	 * @param array $fields Everything the form submits.
	 * @return string Redirect target.
	 */
	private function bulk( array $fields ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Building the request the handler verifies; the nonce comes from the rendered form.
		$_GET = array_merge( isset( $_GET ) ? $_GET : array(), $fields );

		$_REQUEST = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Building the request the handler verifies; the nonce comes from the rendered form.

		return $this->expectRedirect(
			static function () {
				( new Organizations() )->handle_bulk_status();
			}
		);
	}

	/**
	 * Run something that ends in a redirect, and report where it went.
	 *
	 * @param callable $run What to run.
	 * @return string Redirect target.
	 */
	private function expectRedirect( callable $run ) {
		$throw = static function ( $location ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Carried to an assertion in a test, never rendered.
			throw new RedirectException( $location );
		};

		add_filter( 'wp_redirect', $throw );

		try {
			$run();
		} catch ( RedirectException $redirect ) {
			return $redirect->location;
		} finally {
			remove_filter( 'wp_redirect', $throw );
		}

		$this->fail( 'The handler did not redirect.' );
	}
}
