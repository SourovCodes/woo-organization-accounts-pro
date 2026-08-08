<?php
/**
 * Base class for the plugin's WooCommerce emails.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * A WooCommerce email, with the template plumbing stated once.
 *
 * Extending `WC_Email` rather than calling `wp_mail()` is what puts these messages in
 * WooCommerce → Settings → Emails alongside every other one the shop sends: the same
 * on/off switch, the same subject and heading fields, the same header, footer and
 * branding, and the same template override path for a theme. A plugin that sends its
 * own mail instead gets none of that and looks nothing like the rest of the shop.
 */
abstract class Email extends \WC_Email {

	/**
	 * Set up the shared template location.
	 */
	public function __construct() {
		$this->template_base = WOAP_PLUGIN_DIR . 'templates/';

		parent::__construct();
	}

	/**
	 * The variables this email's template needs.
	 *
	 * @return array Template variables.
	 */
	abstract protected function template_args();

	/**
	 * The HTML body.
	 *
	 * @return string Markup.
	 */
	public function get_content_html() {
		return $this->render( $this->template_html, false );
	}

	/**
	 * The plain-text body.
	 *
	 * @return string Text.
	 */
	public function get_content_plain() {
		return $this->render( $this->template_plain, true );
	}

	/**
	 * Render one of this email's templates.
	 *
	 * @param string $template   Template file, relative to the templates directory.
	 * @param bool   $plain_text Whether the plain-text version is being rendered.
	 * @return string Rendered body.
	 */
	private function render( $template, $plain_text ) {
		return wc_get_template_html(
			$template,
			array_merge(
				$this->template_args(),
				array(
					'email_heading'      => $this->get_heading(),
					'additional_content' => $this->get_additional_content(),
					'sent_to_admin'      => false,
					'plain_text'         => $plain_text,
					'email'              => $this,
				)
			),
			'',
			$this->template_base
		);
	}
}
