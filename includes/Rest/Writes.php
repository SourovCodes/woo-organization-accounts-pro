<?php
/**
 * The parts every write route has in common.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Rest;

use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Data\OrganizationRepository;
use WooOrgAccounts\Frontend\AddressFields;

defined( 'ABSPATH' ) || exit;

/**
 * Permission, lookup, address assembly and refusal, shared by the routes that write.
 *
 * The write routes exist for a back-office app: the screen where somebody reviews a
 * registration and approves it, adds a branch, or puts a new employee on the account.
 * That is a different consumer from the till the read routes were written for, and the
 * three things it needs from every route are the same three things, so they live here
 * rather than four times over.
 *
 * **The capability is `manage_woocommerce`, exactly as on the read routes**, and
 * deliberately not one of this plugin's own. Those are granted from a membership and
 * answer "what may this person do to *their* organization?"; the answer to that is
 * never "approve any organization on the site". An app acting for an organization admin
 * would be a different surface with a different question, and it is not this one.
 *
 * **A refusal names the field it is about.** These routes validate with the same
 * `AddressFields::validate()` and `Organization::validate_details()` the web forms use,
 * which key their errors by the field name a *form* posts and mark the field name up in
 * `<strong>` for a page. A REST client wants neither, so the messages are stripped of
 * their markup and the keys are rewritten to the path the client actually sent — which
 * is what lets an app mark the offending input rather than showing one banner over a
 * fourteen-field form. The shape is WordPress's own `rest_invalid_param`: a `params`
 * map inside `data`, so a client that already understands core's refusals understands
 * these.
 */
final class Writes {

	/**
	 * The capability every route in this namespace is gated on.
	 */
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * Whether the current user may write through this namespace.
	 *
	 * @param string $message What they are not allowed to do, for the refusal.
	 * @return true|\WP_Error True when permitted.
	 */
	public static function permission_check( $message ) {
		if ( current_user_can( self::CAPABILITY ) ) {
			return true;
		}

		return new \WP_Error(
			'woap_rest_forbidden',
			$message,
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Look up the organization a request is about.
	 *
	 * Every sub-resource route resolves its parent through this, so a location or a
	 * member is only ever reached below an organization that exists — and the repository
	 * lookups those routes then use are themselves scoped to that organization, which is
	 * the cross-tenant question rather than the permission one.
	 *
	 * @param int $organization_id Organization ID from the route.
	 * @return Organization|\WP_Error The organization, or a 404.
	 */
	public static function organization( $organization_id ) {
		$organization = OrganizationRepository::find( absint( $organization_id ) );

		if ( $organization instanceof Organization ) {
			return $organization;
		}

		return new \WP_Error(
			'woap_rest_organization_not_found',
			sprintf(
				/* translators: %s: the organization noun for the site's mode, for example "Company". */
				__( 'No %s with that identifier exists.', 'woo-organization-accounts-pro' ),
				\WooOrgAccounts\Labels::organization()
			),
			array( 'status' => 404 )
		);
	}

	/**
	 * Merge a submitted address onto the one already stored.
	 *
	 * The merge itself is `AddressFields::merge()`, where the rest of this plugin's
	 * understanding of an address lives. It stays reachable under this name because a
	 * partial address is a REST-shaped problem and this is where a route looks for it.
	 *
	 * @param string $type      AddressFields::BILLING or AddressFields::SHIPPING.
	 * @param mixed  $submitted The address from the request body, if it carried one.
	 * @param array  $current   The address as stored, keyed without the prefix.
	 * @return array The merged address, keyed without the prefix.
	 */
	public static function address( $type, $submitted, array $current ) {
		return AddressFields::merge( $type, $submitted, $current );
	}

	/**
	 * Turn form-shaped validation errors into one REST refusal.
	 *
	 * @param string    $code    Error code for the refusal as a whole.
	 * @param \WP_Error $errors  The errors the validators collected.
	 * @param array     $rewrite Map of field-name prefix to the payload path prefix it becomes.
	 * @return \WP_Error The refusal, carrying a field-keyed `params` map.
	 */
	public static function refuse( $code, \WP_Error $errors, array $rewrite = array() ) {
		$rewrite = array_merge( array( 'woap_' => '' ), $rewrite );
		$params  = array();

		foreach ( $errors->get_error_codes() as $error_code ) {
			$params[ self::path( (string) $error_code, $rewrite ) ] = wp_strip_all_tags(
				(string) $errors->get_error_message( $error_code )
			);
		}

		return new \WP_Error(
			$code,
			wp_strip_all_tags( implode( ' ', $errors->get_error_messages() ) ),
			array(
				'status' => 400,
				'params' => $params,
			)
		);
	}

	/**
	 * The payload path a form field name corresponds to.
	 *
	 * @param string $field   Field name the validator keyed its error by.
	 * @param array  $rewrite Map of prefix to replacement.
	 * @return string Path into the request body.
	 */
	private static function path( $field, array $rewrite ) {
		foreach ( $rewrite as $prefix => $replacement ) {
			if ( 0 === strpos( $field, $prefix ) ) {
				return $replacement . substr( $field, strlen( $prefix ) );
			}
		}

		return $field;
	}

	/**
	 * The refusal for a write the database would not take.
	 *
	 * Every repository here answers a failed write with 0 rather than an exception, and
	 * a route that ignored that would answer 201 for a row that does not exist.
	 *
	 * @param string $message What could not be saved.
	 * @return \WP_Error A 500.
	 */
	public static function not_saved( $message ) {
		return new \WP_Error( 'woap_rest_not_saved', $message, array( 'status' => 500 ) );
	}
}
