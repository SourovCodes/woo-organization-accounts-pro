<?php
/**
 * Settings tests.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Tests;

use WooOrgAccounts\Admin\Settings;
use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Labels;

/**
 * Reading, defaulting and validating the plugin's settings.
 */
class SettingsTest extends TestCase {

	/**
	 * Every declared setting has a default.
	 */
	public function testDefaults() {
		$defaults = Settings::default_settings();

		$this->assertSame( Labels::MODE_BUSINESS, $defaults['organization_mode'] );
		$this->assertTrue( $defaults['require_approval'] );
		$this->assertFalse( $defaults['require_approval_to_sign_in'] );
		$this->assertSame( '', $defaults['tax_id_field'], 'Unset, so a site keeps the form its old checkbox gave it.' );
		$this->assertSame( Organization::TAX_ID_OPTIONAL, Organization::tax_id_field(), 'Insisting on a VAT number is right for one shop and wrong for the next.' );
		$this->assertSame( 7, $defaults['invitation_expiry_days'] );
		$this->assertTrue( $defaults['default_allow_custom_shipping'] );
		$this->assertFalse( $defaults['remove_data_on_uninstall'] );
	}

	/**
	 * A partially stored option is filled in from the defaults.
	 */
	public function testStoredSettingsAreMergedOverDefaults() {
		update_option( Settings::OPTION_KEY, array( 'require_approval' => false ), false );

		$settings = Settings::get_settings();

		$this->assertFalse( $settings['require_approval'] );
		$this->assertSame( 7, $settings['invitation_expiry_days'] );
	}

	/**
	 * A corrupt option does not take the settings screen down with it.
	 */
	public function testCorruptOptionFallsBackToDefaults() {
		update_option( Settings::OPTION_KEY, 'not an array', false );

		$this->assertSame( Settings::default_settings(), Settings::get_settings() );
	}

	/**
	 * Unchecked checkboxes are stored as false rather than left at their old value.
	 */
	public function testCheckboxesAreStoredAsBooleans() {
		$clean = ( new Settings() )->sanitize( array( 'organization_mode' => Labels::MODE_BUSINESS ) );

		$this->assertFalse( $clean['require_approval'] );
		$this->assertFalse( $clean['require_approval_to_sign_in'] );
		$this->assertFalse( $clean['default_allow_custom_shipping'] );
		$this->assertFalse( $clean['remove_data_on_uninstall'] );

		$ticked = ( new Settings() )->sanitize(
			array(
				'organization_mode' => Labels::MODE_BUSINESS,
				'require_approval'  => '1',
			)
		);

		$this->assertTrue( $ticked['require_approval'] );
	}

	/**
	 * The two approval settings are separate answers to separate questions.
	 *
	 * Approval gates ordering; the sign-in setting gates the account itself. A shop
	 * that approves nothing may still want a suspended account locked out, so the
	 * second is not folded into the first and is not conditional on it either.
	 */
	public function testTheSignInGateIsIndependentOfTheOrderingGate() {
		$clean = ( new Settings() )->sanitize(
			array(
				'organization_mode'           => Labels::MODE_BUSINESS,
				'require_approval_to_sign_in' => '1',
			)
		);

		$this->assertFalse( $clean['require_approval'] );
		$this->assertTrue( $clean['require_approval_to_sign_in'] );
	}

	/**
	 * The expiry is clamped rather than trusted.
	 */
	public function testExpiryIsClamped() {
		$clean = ( new Settings() )->sanitize( array( 'invitation_expiry_days' => '9999' ) );

		$this->assertSame( 365, $clean['invitation_expiry_days'] );

		$negative = ( new Settings() )->sanitize( array( 'invitation_expiry_days' => '-5' ) );

		$this->assertSame( 5, $negative['invitation_expiry_days'], 'absint() takes the magnitude.' );
	}

	/**
	 * A setting that is not one of ours cannot be introduced through the form.
	 */
	public function testUnknownSettingsAreDropped() {
		$clean = ( new Settings() )->sanitize( array( 'secret_backdoor' => 'yes' ) );

		$this->assertArrayNotHasKey( 'secret_backdoor', $clean );
	}

	/**
	 * The registration page must be a page; anything else keeps the stored value.
	 */
	public function testRegistrationPageMustBeAPage() {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$post_id = self::factory()->post->create( array( 'post_type' => 'post' ) );

		$this->set_setting( 'registration_page_id', $page_id );

		$clean = ( new Settings() )->sanitize( array( 'registration_page_id' => $post_id ) );

		$this->assertSame( $page_id, $clean['registration_page_id'] );

		$another = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$clean   = ( new Settings() )->sanitize( array( 'registration_page_id' => $another ) );

		$this->assertSame( $another, $clean['registration_page_id'] );
	}

	/**
	 * The invitation lifetime is stated in seconds, and zero means never.
	 */
	public function testInvitationLifetime() {
		$this->set_setting( 'invitation_expiry_days', 3 );

		$this->assertSame( 3 * DAY_IN_SECONDS, Settings::invitation_lifetime() );

		$this->set_setting( 'invitation_expiry_days', 0 );

		$this->assertSame( 0, Settings::invitation_lifetime() );
	}

	/**
	 * There is no guest-checkout setting, because there is no choice to make.
	 */
	public function testNoGuestCheckoutSetting() {
		$this->assertArrayNotHasKey( 'enable_guest_checkout', Settings::default_settings() );
		$this->assertArrayNotHasKey( 'guest_checkout', Settings::default_settings() );
	}

	/**
	 * A site saved before the three-way setting existed keeps the form it had.
	 *
	 * The old checkbox only knew "required" and "not required", and both of those meant
	 * the field was shown — so an update must not hide it on anybody's shop.
	 */
	public function testTheTaxIdFieldFollowsTheOldCheckboxUntilItIsSet() {
		$this->set_setting( 'tax_id_field', '' );
		$this->set_setting( 'require_tax_id', false );

		$this->assertSame( Organization::TAX_ID_OPTIONAL, Organization::tax_id_field() );
		$this->assertTrue( Organization::tax_id_collected() );
		$this->assertFalse( Organization::tax_id_required() );

		$this->set_setting( 'require_tax_id', true );

		$this->assertSame( Organization::TAX_ID_REQUIRED, Organization::tax_id_field() );
		$this->assertTrue( Organization::tax_id_required() );
	}

	/**
	 * "Not asked" wins over the old checkbox, so a hidden field is never also required.
	 */
	public function testATaxIdThatIsNotAskedForIsNotRequired() {
		$this->set_setting( 'require_tax_id', true );
		$this->set_setting( 'tax_id_field', Organization::TAX_ID_OFF );

		$this->assertFalse( Organization::tax_id_collected() );
		$this->assertFalse( Organization::tax_id_required() );
	}

	/**
	 * The screen stores one of three values, and retires the old checkbox.
	 */
	public function testTheTaxIdFieldIsSanitisedToOneOfThreeValues() {
		$settings = new Settings();

		foreach ( array( Organization::TAX_ID_OFF, Organization::TAX_ID_OPTIONAL, Organization::TAX_ID_REQUIRED ) as $value ) {
			$clean = $settings->sanitize(
				array(
					'organization_mode' => Labels::MODE_EDUCATION,
					'tax_id_field'      => $value,
				)
			);

			$this->assertSame( $value, $clean['tax_id_field'] );
			$this->assertArrayNotHasKey( 'require_tax_id', $clean, 'The retired checkbox was written back.' );
		}

		$this->set_setting( 'tax_id_field', Organization::TAX_ID_OFF );

		$clean = $settings->sanitize(
			array(
				'organization_mode' => Labels::MODE_EDUCATION,
				'tax_id_field'      => 'sometimes',
			)
		);

		$this->assertSame( Organization::TAX_ID_OFF, $clean['tax_id_field'], 'An unknown value replaced the stored one.' );
	}
}
