<?php
/**
 * Shared test case.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Tests;

use WooOrgAccounts\Admin\Settings;
use WooOrgAccounts\Data\Location;
use WooOrgAccounts\Data\LocationRepository;
use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Data\MemberRepository;
use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Data\OrganizationRepository;
use WooOrgAccounts\Membership\Context;
use WooOrgAccounts\Roles;

/**
 * Base class for the plugin's tests.
 *
 * Carries the factory helpers every suite needs and, more importantly, resets the
 * per-request memos between tests. The membership lookup is deliberately cached for
 * the life of a request, and a test run is one long request as far as PHP is
 * concerned — so without this, the second test in a file sees the first one's answers.
 */
abstract class TestCase extends \WP_UnitTestCase {

	/**
	 * Reset the plugin's request-scoped state before each test.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		Context::flush();
		wp_set_current_user( 0 );
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
	}

	/**
	 * Reset it again afterwards, so a failing test cannot leak into the next file.
	 *
	 * @return void
	 */
	public function tear_down() {
		Context::flush();
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();

		parent::tear_down();
	}

	/**
	 * Create an organization.
	 *
	 * @param array $props Column values to override.
	 * @return Organization The saved organization.
	 */
	protected function make_organization( array $props = array() ) {
		$organization = new Organization();

		$organization->set_props(
			array_merge(
				array(
					'name'   => 'Acme GmbH',
					'email'  => 'buy@acme.test',
					'status' => Organization::STATUS_ACTIVE,
				),
				$props
			)
		);

		if ( ! isset( $props['billing_country'] ) ) {
			$organization->set_billing_address(
				array(
					'first_name' => 'Ada',
					'last_name'  => 'Byron',
					'company'    => 'Acme GmbH',
					'address_1'  => '1 Hauptstrasse',
					'city'       => 'Berlin',
					'postcode'   => '10115',
					'country'    => 'DE',
					'email'      => 'buy@acme.test',
				)
			);
		}

		OrganizationRepository::save( $organization );

		return $organization;
	}

	/**
	 * Create a user and make them a member of an organization.
	 *
	 * @param Organization $organization Organization to join.
	 * @param string       $role         One of the Member::ROLE_* constants.
	 * @param array        $props        Member column values to override.
	 * @return Member The saved membership.
	 */
	protected function make_member( Organization $organization, $role = Member::ROLE_MEMBER, array $props = array() ) {
		$user_id = self::factory()->user->create(
			array(
				'role'       => Roles::wordpress_role( $role ),
				'user_email' => uniqid( 'member', true ) . '@acme.test',
			)
		);

		$member = new Member();
		$member->set_props(
			array_merge(
				array(
					'organization_id' => $organization->get_id(),
					'user_id'         => $user_id,
					'role'            => $role,
					'status'          => Member::STATUS_ACTIVE,
				),
				$props
			)
		);

		MemberRepository::save( $member );
		Context::flush();

		return $member;
	}

	/**
	 * Create a location for an organization.
	 *
	 * @param Organization $organization Organization the location belongs to.
	 * @param array        $props        Column values to override.
	 * @return Location The saved location.
	 */
	protected function make_location( Organization $organization, array $props = array() ) {
		$location = new Location();

		$location->set_props(
			array_merge(
				array(
					'organization_id' => $organization->get_id(),
					'name'            => 'Warehouse North',
					'first_name'      => 'Grace',
					'last_name'       => 'Hopper',
					'company'         => 'Warehouse North',
					'address_1'       => '9 Lagerweg',
					'city'            => 'Hamburg',
					'postcode'        => '20095',
					'country'         => 'DE',
					'phone'           => '+49 40 123456',
				),
				$props
			)
		);

		LocationRepository::save( $location );

		return $location;
	}

	/**
	 * Sign a membership's user in.
	 *
	 * @param Member $member Membership to act as.
	 * @return int The user ID.
	 */
	protected function act_as( Member $member ) {
		wp_set_current_user( $member->get_user_id() );
		Context::flush();

		return $member->get_user_id();
	}

	/**
	 * Replace one plugin setting for the duration of a test.
	 *
	 * @param string $key   Setting name.
	 * @param mixed  $value New value.
	 * @return void
	 */
	protected function set_setting( $key, $value ) {
		$settings         = Settings::get_settings();
		$settings[ $key ] = $value;

		update_option( Settings::OPTION_KEY, $settings, false );
	}
}
