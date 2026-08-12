<?php
/**
 * The key that decides which rows of an import are one organization.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Import;

use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Data\OrganizationRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Groups the rows of a flat customer export into organizations.
 *
 * A shop that had no organization model exports one row per person, and the only
 * evidence of which of them work for the same account is the address they billed to.
 * The key is the company name and the street address: company, address line, postcode
 * and town, each normalised.
 *
 * **The person's name is deliberately not part of the key.** The person is the member,
 * not the organization, so keying on them splits the one case this plugin exists for —
 * two colleagues at one address become two organizations that can neither see each
 * other's orders nor share a delivery address. Company plus full street address is
 * already specific enough that a false merge would need two unrelated parties to share
 * a company name *and* a street *and* a postcode.
 *
 * The key errs towards splitting, because the two mistakes are not equally bad. An
 * organization that should have been one and arrived as two is an inconvenience a
 * merge puts right. Two unrelated customers merged into one organization is a privacy
 * failure: each can read the other's orders and ship to the other's addresses, and no
 * later merge undoes having shown them.
 *
 * **The key is derived, never stored.** An organization's own columns carry everything
 * it is built from, so `for_organization()` re-derives the same key from a row that is
 * already in the database. That is what makes a second run of the same file import
 * nothing, what lets a follow-up export join the organizations the first one created,
 * and what lets an import find an organization that registered on the site by hand —
 * and it costs no column, so no schema change and no `WOAP_DB_VERSION` bump.
 */
final class OrganizationKey {

	/**
	 * Reduce a value to the form the key compares.
	 *
	 * Case, accents and punctuation all vary between two people typing the same
	 * company name, and none of them carries meaning here. `ß` is expanded before
	 * accents are folded, because `remove_accents()` turns it into a single `s` and
	 * would leave "Straße" and "Strasse" looking like different streets.
	 *
	 * @param string $value Raw value.
	 * @return string Normalised value.
	 */
	public static function normalise( $value ) {
		$value = str_replace( array( 'ß', 'ẞ' ), 'ss', (string) $value );
		$value = remove_accents( $value );
		$value = strtolower( $value );
		$value = preg_replace( '/[^a-z0-9]+/', ' ', $value );

		return trim( (string) $value );
	}

	/**
	 * Drop the legal form from a company name.
	 *
	 * Off unless the import asks for it. It merges "Jouets au petit bois" with "Jouets
	 * au petit bois sàrl" at one address, which is right, and it would merge two
	 * genuinely different companies whose names differ only by their legal form at a
	 * shared address, which is not — a business centre is enough for that to happen.
	 * The shop knows its own customers and this class does not, so it is a choice made
	 * on the import screen rather than a rule applied here.
	 *
	 * @param string $company Normalised company name.
	 * @return string The name without its legal form, or the name unchanged when that
	 *                would leave nothing behind.
	 */
	public static function without_legal_form( $company ) {
		$forms    = array( 'ag', 'gmbh', 'sa', 'sagl', 'sarl', 'kg', 'ohg', 'eg', 'ug', 'kgaa', 'ltd', 'llc', 'inc', 'plc', 'bv', 'nv', 'aps', 'oy', 'ab' );
		$stripped = preg_replace( '/\b(' . implode( '|', $forms ) . ')\b/', ' ', (string) $company );
		$stripped = trim( preg_replace( '/\s+/', ' ', (string) $stripped ) );

		return '' === $stripped ? (string) $company : $stripped;
	}

	/**
	 * Build the key for a billing address.
	 *
	 * The company name falls back to the person's, because a shop selling to sole
	 * traders and to schools has customers with no company name at all and they still
	 * have to become an organization — 72 of the 647 rows this was written against.
	 * Nothing is invented to fill the column: the fallback happens here, in the key,
	 * and the organization's `billing_company` is left as empty as it arrived.
	 *
	 * @param array $address Billing address keyed as WooCommerce names its fields.
	 * @param bool  $ignore_legal_form Whether to strip the company's legal form first.
	 * @return string The key, or an empty string when there is nothing to key on.
	 */
	public static function for_address( array $address, $ignore_legal_form = false ) {
		$company = self::normalise( $address['company'] ?? '' );

		if ( '' === $company ) {
			$company = self::normalise( trim( ( $address['first_name'] ?? '' ) . ' ' . ( $address['last_name'] ?? '' ) ) );
		}

		if ( $ignore_legal_form ) {
			$company = self::without_legal_form( $company );
		}

		$parts = array(
			$company,
			self::normalise( $address['address_1'] ?? '' ),
			self::normalise( $address['postcode'] ?? '' ),
			self::normalise( $address['city'] ?? '' ),
		);

		$key = implode( '|', $parts );

		if ( '|||' === $key ) {
			$key = '';
		}

		/**
		 * Filters the key that decides which imported rows are one organization.
		 *
		 * A shop whose export carries something better than an address — a parent
		 * account number, a VAT number, a customer group — can answer with that
		 * instead. Returning an empty string leaves the row on its own.
		 *
		 * @since 0.7.0
		 *
		 * @param string $key     The derived key.
		 * @param array  $address The billing address it was derived from.
		 */
		return (string) apply_filters( 'woo_org_accounts_import_organization_key', $key, $address );
	}

	/**
	 * Build the key for an organization that is already stored.
	 *
	 * Reads the same fields `for_address()` does, so a row and the organization it
	 * created answer with the same key.
	 *
	 * @param Organization $organization Organization to key.
	 * @param bool         $ignore_legal_form Whether to strip the company's legal form first.
	 * @return string The key, or an empty string when there is nothing to key on.
	 */
	public static function for_organization( Organization $organization, $ignore_legal_form = false ) {
		return self::for_address( $organization->get_billing_address(), $ignore_legal_form );
	}

	/**
	 * Every organization on the site, indexed by its key.
	 *
	 * Rebuilt at the start of each batch rather than carried in the import's own state,
	 * so an organization created by a registration, by wp-admin or by an earlier run
	 * halfway through is found by the rest of this one. Where two organizations answer
	 * with the same key — which a hand-made pair can — the lower ID wins, so the
	 * answer does not depend on the order rows arrive in.
	 *
	 * @param bool $ignore_legal_form Whether to strip company legal forms first.
	 * @return array Map of key to organization ID.
	 */
	public static function index( $ignore_legal_form = false ) {
		$index = array();

		foreach ( OrganizationRepository::query( array( 'orderby' => 'id' ) ) as $organization ) {
			$key = self::for_organization( $organization, $ignore_legal_form );

			if ( '' !== $key && ! isset( $index[ $key ] ) ) {
				$index[ $key ] = $organization->get_id();
			}
		}

		return $index;
	}

	/**
	 * The fields that decide whether two delivery addresses are the same place.
	 *
	 * The address and the company at it, and deliberately **not the contact name or the
	 * phone number**. Those say who to ask for when the van arrives, not where it goes.
	 * A flat export carries one row per employee, each naming themselves as the contact
	 * at the company's single loading bay — so keying on the name turned one warehouse
	 * into one location per employee, an identical address repeated down the checkout's
	 * delivery list with nothing to tell the copies apart.
	 *
	 * `address_2` is in the key, which is where a floor or a unit number lives, so two
	 * genuinely different destinations in one building still count as two.
	 *
	 * @var string[]
	 */
	const LOCATION_FIELDS = array( 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' );

	/**
	 * The key an address is compared by when deciding whether a location already exists.
	 *
	 * @param array $address Shipping address keyed as WooCommerce names its fields.
	 * @return string Comparison key.
	 */
	public static function for_location( array $address ) {
		$parts = array();

		foreach ( self::LOCATION_FIELDS as $field ) {
			$parts[] = self::normalise( $address[ $field ] ?? '' );
		}

		return implode( '|', $parts );
	}
}
