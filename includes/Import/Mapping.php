<?php
/**
 * The columns the importer reads, and how it finds them in a file.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Import;

use WooOrgAccounts\Labels;

defined( 'ABSPATH' ) || exit;

/**
 * Maps the columns of somebody else's export onto the fields this plugin stores.
 *
 * Every shop's export names its columns differently, so the mapping is guessed from
 * the headings and then shown on screen for the person running the import to correct.
 * Guessing and confirming, rather than guessing alone: a heading this class does not
 * recognise would otherwise be silently dropped, and a dropped postcode column is 600
 * organizations that cannot be shipped to.
 *
 * Only one column is required. Everything else is allowed to be missing, because a row
 * with a poor address still becomes an account somebody can sign in to and the shop can
 * repair — and an account that was never created is a customer who has to be told to
 * register again.
 */
final class Mapping {

	/**
	 * The address blocks, and the fields each one carries.
	 *
	 * The names after the prefix are WooCommerce's own field names, so a mapped row can
	 * be handed to `AddressFields` and to an order without translation.
	 *
	 * @var string[]
	 */
	const ADDRESS_FIELDS = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' );

	/**
	 * Every field the importer can read, with its label and the headings it answers to.
	 *
	 * The aliases are matched after `normalise_header()`, so they are lowercase with
	 * underscores between words. German ones are included because a German shop's
	 * export is written in German, which is the case this was built for.
	 *
	 * @return array Map of field name to definition.
	 */
	public static function fields() {
		$fields = array(
			'email'           => array(
				'label'    => __( 'Email address', 'woo-organization-accounts-pro' ),
				'group'    => 'account',
				'required' => true,
				'aliases'  => array( 'email', 'e_mail', 'email_address', 'user_email', 'customer_email', 'mail', 'e_mail_adresse', 'emailadresse' ),
			),
			'first_name'      => array(
				'label'    => __( 'First name', 'woo-organization-accounts-pro' ),
				'group'    => 'account',
				'required' => false,
				'aliases'  => array( 'first_name', 'firstname', 'given_name', 'vorname' ),
			),
			'last_name'       => array(
				'label'    => __( 'Last name', 'woo-organization-accounts-pro' ),
				'group'    => 'account',
				'required' => false,
				'aliases'  => array( 'last_name', 'lastname', 'surname', 'family_name', 'nachname' ),
			),
			'customer_number' => array(
				'label'    => __( 'Customer number', 'woo-organization-accounts-pro' ),
				'group'    => 'account',
				'required' => false,
				'aliases'  => array( 'customer_number', 'customernumber', 'customer_id', 'kundennummer', 'kunden_nr' ),
			),
			'active'          => array(
				'label'    => __( 'Active', 'woo-organization-accounts-pro' ),
				'group'    => 'account',
				'required' => false,
				'aliases'  => array( 'active', 'is_active', 'enabled', 'aktiv', 'status' ),
			),
			'organization'    => array(
				'label'    => sprintf(
					/* translators: %s: the organization noun for the site's mode, for example "Company". */
					__( '%s name', 'woo-organization-accounts-pro' ),
					Labels::organization()
				),
				'group'    => 'account',
				'required' => false,
				'aliases'  => array( 'organization', 'organisation', 'organization_name', 'company_name', 'firmenname' ),
			),
			'tax_id'          => array(
				'label'    => __( 'VAT number, tax ID or registration number', 'woo-organization-accounts-pro' ),
				'group'    => 'account',
				'required' => false,
				'aliases'  => array( 'tax_id', 'vat_id', 'vat_number', 'vat', 'ust_id', 'ustid', 'uid', 'mwst_nummer', 'steuernummer' ),
			),
		);

		foreach ( array( 'billing', 'shipping' ) as $type ) {
			foreach ( self::address_field_definitions( $type ) as $field => $definition ) {
				$fields[ $field ] = $definition;
			}
		}

		return $fields;
	}

	/**
	 * The definitions for one address block.
	 *
	 * @param string $type 'billing' or 'shipping'.
	 * @return array Map of field name to definition.
	 */
	private static function address_field_definitions( $type ) {
		$labels = array(
			'first_name' => __( 'First name', 'woo-organization-accounts-pro' ),
			'last_name'  => __( 'Last name', 'woo-organization-accounts-pro' ),
			'company'    => __( 'Company', 'woo-organization-accounts-pro' ),
			'address_1'  => __( 'Street address', 'woo-organization-accounts-pro' ),
			'address_2'  => __( 'Address line 2', 'woo-organization-accounts-pro' ),
			'city'       => __( 'Town / City', 'woo-organization-accounts-pro' ),
			'state'      => __( 'State / County', 'woo-organization-accounts-pro' ),
			'postcode'   => __( 'Postcode / ZIP', 'woo-organization-accounts-pro' ),
			'country'    => __( 'Country', 'woo-organization-accounts-pro' ),
			'phone'      => __( 'Phone', 'woo-organization-accounts-pro' ),
		);

		$suffixes = array(
			'first_name' => array( 'first_name', 'firstname', 'vorname' ),
			'last_name'  => array( 'last_name', 'lastname', 'surname', 'nachname' ),
			'company'    => array( 'company', 'company_name', 'firma', 'organisation', 'organization' ),
			'address_1'  => array( 'address_1', 'address1', 'address', 'street', 'street_address', 'strasse', 'street_1' ),
			'address_2'  => array( 'address_2', 'address2', 'street_2', 'additional_address_line_1', 'adresszusatz' ),
			'city'       => array( 'city', 'town', 'ort', 'stadt' ),
			'state'      => array( 'state', 'county', 'province', 'region', 'kanton', 'bundesland' ),
			'postcode'   => array( 'postcode', 'post_code', 'zip', 'zipcode', 'zip_code', 'plz', 'postleitzahl' ),
			'country'    => array( 'country', 'country_code', 'land', 'country_iso' ),
			'phone'      => array( 'phone', 'phone_number', 'telephone', 'tel', 'telefon', 'telefonnummer' ),
		);

		$prefixes = 'billing' === $type
			? array( 'billing', 'invoice', 'rechnung', 'rechnungsadresse' )
			: array( 'shipping', 'delivery', 'lieferung', 'lieferadresse', 'versand' );

		$definitions = array();

		foreach ( $labels as $field => $label ) {
			$aliases = array();

			foreach ( $prefixes as $prefix ) {
				foreach ( $suffixes[ $field ] as $suffix ) {
					$aliases[] = $prefix . '_' . $suffix;
				}
			}

			$definitions[ $type . '_' . $field ] = array(
				'label'    => $label,
				'group'    => $type,
				'required' => false,
				'aliases'  => $aliases,
			);
		}

		return $definitions;
	}

	/**
	 * The display name of each group of fields.
	 *
	 * @return array Map of group key to translated heading.
	 */
	public static function groups() {
		return array(
			'account'  => __( 'Account', 'woo-organization-accounts-pro' ),
			'billing'  => __( 'Billing address', 'woo-organization-accounts-pro' ),
			'shipping' => sprintf(
				/* translators: %s: the location noun for the site's mode, for example "Branch". */
				__( 'Delivery address (imported as a %s)', 'woo-organization-accounts-pro' ),
				Labels::location()
			),
		);
	}

	/**
	 * Reduce a column heading to the form aliases are matched against.
	 *
	 * @param string $header Column heading as it appears in the file.
	 * @return string Normalised heading.
	 */
	public static function normalise_header( $header ) {
		$header = remove_accents( (string) $header );
		$header = strtolower( $header );
		$header = preg_replace( '/[^a-z0-9]+/', '_', $header );

		return trim( (string) $header, '_' );
	}

	/**
	 * Guess which column of a file feeds each field.
	 *
	 * An exact alias match wins over a heading that merely contains one, so a file with
	 * both `email` and `billing_email` gives the account's address to `email`. A column
	 * is never offered to two fields, because a mapping that reads one heading twice is
	 * always a mistake somewhere.
	 *
	 * @param string[] $headers Column headings, in file order.
	 * @return array Map of field name to heading, with an empty string for anything unmatched.
	 */
	public static function detect( array $headers ) {
		$normalised = array();

		foreach ( $headers as $header ) {
			$normalised[ $header ] = self::normalise_header( $header );
		}

		$mapping = array_fill_keys( array_keys( self::fields() ), '' );
		$taken   = array();

		foreach ( array( true, false ) as $exact ) {
			foreach ( self::fields() as $field => $definition ) {
				if ( '' !== $mapping[ $field ] ) {
					continue;
				}

				foreach ( $normalised as $header => $candidate ) {
					if ( isset( $taken[ $header ] ) ) {
						continue;
					}

					if ( self::matches( $candidate, $definition['aliases'], $exact ) ) {
						$mapping[ $field ] = (string) $header;
						$taken[ $header ]  = true;
						break;
					}
				}
			}
		}

		return $mapping;
	}

	/**
	 * Whether a normalised heading answers to one of a field's aliases.
	 *
	 * @param string   $header  Normalised heading.
	 * @param string[] $aliases Aliases to test.
	 * @param bool     $exact   True to require the whole heading to match.
	 * @return bool True on a match.
	 */
	private static function matches( $header, array $aliases, $exact ) {
		foreach ( $aliases as $alias ) {
			if ( $exact ) {
				if ( $header === $alias ) {
					return true;
				}

				continue;
			}

			/*
			 * Bounded on both sides, so `billing_zipcode` matches the `zipcode` alias
			 * while `billing_country_state` does not match `country`. A substring test
			 * without the boundaries hands the country column to whichever field asks
			 * first.
			 */
			if ( preg_match( '/(^|_)' . preg_quote( $alias, '/' ) . '($|_)/', $header ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Clean a mapping submitted from the screen.
	 *
	 * Anything naming a column the file does not have is dropped rather than kept, so a
	 * mapping cannot outlive the file it was made for.
	 *
	 * @param array    $submitted Map of field name to heading.
	 * @param string[] $headers   The file's headings.
	 * @return array Map of field name to heading.
	 */
	public static function sanitize( array $submitted, array $headers ) {
		$mapping = array_fill_keys( array_keys( self::fields() ), '' );

		foreach ( $mapping as $field => $unused ) {
			$header = isset( $submitted[ $field ] ) ? (string) $submitted[ $field ] : '';

			if ( in_array( $header, $headers, true ) ) {
				$mapping[ $field ] = $header;
			}
		}

		return $mapping;
	}

	/**
	 * The labels of the required fields a mapping leaves unanswered.
	 *
	 * @param array $mapping Map of field name to heading.
	 * @return string[] Labels.
	 */
	public static function missing_required( array $mapping ) {
		$missing = array();

		foreach ( self::fields() as $field => $definition ) {
			if ( $definition['required'] && '' === (string) ( $mapping[ $field ] ?? '' ) ) {
				$missing[] = $definition['label'];
			}
		}

		return $missing;
	}
}
