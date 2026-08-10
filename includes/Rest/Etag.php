<?php
/**
 * ETag revalidation for the plugin's snapshot routes.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Answers "does the client already hold this payload?" for a polling device.
 *
 * Every read-only route here is polled on an interval by a till, and most polls find
 * nothing changed. An ETag over the payload turns those polls into a hash comparison
 * and a 304 instead of a transfer — which is the difference between a sync that costs
 * nothing and one that re-sends the shop's every organization each interval.
 */
final class Etag {

	/**
	 * The ETag for a payload, quoted as the header wants it.
	 *
	 * A hash of the encoded payload rather than of any stored version number, because
	 * half the data behind these routes carries no version or date at all — a member
	 * row records its creation and nothing else. Hashing what is actually sent means a
	 * change anywhere below the surface still changes the tag.
	 *
	 * @param mixed $payload The response data.
	 * @return string Quoted ETag.
	 */
	public static function for_payload( $payload ) {
		return '"' . md5( (string) wp_json_encode( $payload ) ) . '"';
	}

	/**
	 * Whether the client already holds this exact payload.
	 *
	 * The header may carry several validators and may mark them weak. Weak and strong
	 * are the same answer here — the payload either hashes to the same thing or it
	 * does not — so the `W/` prefix is stripped rather than rejected.
	 *
	 * @param string|null $header The If-None-Match header, if any.
	 * @param string      $etag   The ETag the payload hashes to, quoted.
	 * @return bool True when the client's copy is current.
	 */
	public static function matches( $header, $etag ) {
		if ( empty( $header ) ) {
			return false;
		}

		foreach ( explode( ',', (string) $header ) as $candidate ) {
			$candidate = trim( $candidate );

			if ( '*' === $candidate ) {
				return true;
			}

			if ( 0 === stripos( $candidate, 'W/' ) ) {
				$candidate = substr( $candidate, 2 );
			}

			if ( hash_equals( $etag, $candidate ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build the response for a payload: a 304 when the client holds it, else the data.
	 *
	 * The ETag header is set in both cases — a 304 without one would leave the client
	 * unable to revalidate next time.
	 *
	 * @param mixed            $payload The response data.
	 * @param \WP_REST_Request $request The request, read for If-None-Match.
	 * @return \WP_REST_Response The response.
	 */
	public static function respond( $payload, $request ) {
		$etag = self::for_payload( $payload );

		if ( self::matches( $request->get_header( 'if_none_match' ), $etag ) ) {
			$response = new \WP_REST_Response( null, 304 );
		} else {
			$response = new \WP_REST_Response( $payload, 200 );
		}

		$response->header( 'ETag', $etag );

		return $response;
	}
}
