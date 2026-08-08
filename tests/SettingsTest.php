<?php
/**
 * Settings tests.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Tests;

use WooOrgAccounts\Admin\Settings;
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
}
