<?php
/**
 * Organization registration and invitation acceptance.
 *
 * @package WooOrgAccounts
 */

namespace WooOrgAccounts\Frontend;

use WooOrgAccounts\Admin\Settings;
use WooOrgAccounts\Data\Invitation;
use WooOrgAccounts\Data\InvitationRepository;
use WooOrgAccounts\Data\Member;
use WooOrgAccounts\Data\MemberRepository;
use WooOrgAccounts\Data\Organization;
use WooOrgAccounts\Data\OrganizationRepository;
use WooOrgAccounts\Labels;
use WooOrgAccounts\LoginGate;
use WooOrgAccounts\Members\Invitations;
use WooOrgAccounts\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * The only two ways an account is created on this site.
 *
 * An organization registers, which creates the organization and its first admin; or
 * somebody redeems an invitation, which creates a member of an organization that
 * already exists. WooCommerce's own registration form is switched off, because an
 * account belonging to no organization could not buy anything and would only be a
 * dead end for whoever created it.
 *
 * Both flows share one shortcode and one page. Which form the page shows depends on
 * whether the request carries an invitation token.
 */
class Registration {

	/**
	 * Shortcode that renders the form.
	 */
	const SHORTCODE = 'woap_organization_registration';

	/**
	 * Nonce action for the organization registration form.
	 */
	const REGISTER_ACTION = 'woap_register_organization';

	/**
	 * Nonce action for the invitation acceptance form.
	 */
	const JOIN_ACTION = 'woap_accept_invitation';

	/**
	 * Field a bot fills in and a person never sees.
	 */
	const HONEYPOT_FIELD = 'woap_website';

	/**
	 * Query argument asking the page to report an account that is awaiting approval.
	 */
	const PENDING_VAR = 'woap_pending';

	/**
	 * Errors raised while processing a submission.
	 *
	 * Held on the class rather than in the WooCommerce notice session, because this
	 * form is on an ordinary page that need not have started a session, and because
	 * the errors have to survive into the same request that re-renders the form.
	 *
	 * @var \WP_Error|null
	 */
	private static $errors = null;

	/**
	 * The values that were submitted, sanitised, so the form can be re-filled.
	 *
	 * @var array
	 */
	private static $submitted = array();

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
		add_action( 'template_redirect', array( $this, 'redirect_register_action' ) );
		add_action( 'template_redirect', array( $this, 'maybe_process' ) );

		/*
		 * WooCommerce's own account creation is switched off rather than hidden. These
		 * are pre_option filters, so the setting cannot be turned back on from
		 * WooCommerce → Accounts while the plugin is active — which is the point: an
		 * account with no organization behind it cannot check out, so offering to
		 * create one would only produce a customer who cannot buy.
		 */
		add_filter( 'pre_option_woocommerce_enable_myaccount_registration', array( $this, 'disable_woocommerce_registration' ) );
		add_filter( 'pre_option_woocommerce_enable_signup_and_login_from_checkout', array( $this, 'disable_woocommerce_registration' ) );
		add_filter( 'pre_option_users_can_register', array( $this, 'disable_wordpress_registration' ) );

		add_action( 'woocommerce_before_customer_login_form', array( $this, 'render_registration_link' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'woodmart_get_page_layout', array( $this, 'page_layout' ) );
	}

	/**
	 * Give the registration page Woodmart's full-width layout.
	 *
	 * The page is an ordinary WordPress page, so the theme gives it whatever the site's
	 * default layout is — on a stock install, a right sidebar of blog widgets. A
	 * twenty-field form including a full billing address then renders in nine columns
	 * next to Categories and Recent Posts. Woodmart makes the same correction for its
	 * own account pages, which is why those look right and this one did not.
	 *
	 * A layout set explicitly on the page wins: this only supplies the default the
	 * theme would otherwise take from the blog. That keeps one answer to the question
	 * rather than the plugin and the page metabox disagreeing.
	 *
	 * @param string $layout Layout Woodmart resolved.
	 * @return string Layout to use.
	 */
	public function page_layout( $layout ) {
		$page_id = self::page_id();

		if ( 0 === $page_id || ! is_page( $page_id ) ) {
			return $layout;
		}

		if ( '' !== (string) get_post_meta( $page_id, '_woodmart_main_layout', true ) ) {
			return $layout;
		}

		return 'full-width';
	}

	/**
	 * The page the registration shortcode lives on.
	 *
	 * @return int Page ID, or 0 when the site has none.
	 */
	public static function page_id() {
		return absint( Settings::get( 'registration_page_id', 0 ) );
	}

	/**
	 * The URL of the registration page.
	 *
	 * @return string URL, or an empty string when there is no page to point at.
	 */
	public static function page_url() {
		$page_id = self::page_id();

		if ( 0 === $page_id ) {
			return '';
		}

		return (string) get_permalink( $page_id );
	}

	/**
	 * Send `?action=register` to the registration page.
	 *
	 * `?action=register` on My Account is Woodmart's own signal for "show me the
	 * register side": the header dropdown's *Create an Account* link, the login page's
	 * *Create an Account* button and the theme's register form all use it. WooCommerce's
	 * registration is switched off while this plugin is active, so all of those now land
	 * on a page showing nothing but the login form — the visitor asked to sign up and
	 * the site answered by asking them to sign in.
	 *
	 * Redirecting rather than rendering the form here keeps one registration screen with
	 * one URL, which is the one that has the billing address block, the honeypot and the
	 * full-width layout on it.
	 *
	 * Only GET requests: a POST to that URL is a form submission, and swallowing one in
	 * a redirect would lose whatever it carried.
	 *
	 * @return void
	 */
	public function redirect_register_action() {
		if ( ! is_account_page() ) {
			return;
		}

		if ( 'GET' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a link's own argument to decide where it should have gone; nothing is written.
		$requested = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

		if ( 'register' !== $requested ) {
			return;
		}

		/*
		 * Somebody already signed in has no use for a registration form, and the
		 * argument would otherwise sit in the URL of their account dashboard.
		 */
		$destination = is_user_logged_in() ? (string) wc_get_page_permalink( 'myaccount' ) : self::page_url();

		if ( '' === $destination ) {
			return;
		}

		wp_safe_redirect( $destination );
		exit;
	}

	/**
	 * Load WooCommerce's country and address scripts on the registration page.
	 *
	 * Without them the state field never follows the country, and a customer in a
	 * country with a state list is left typing one by hand.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		$page_id = self::page_id();

		if ( 0 === $page_id || ! is_page( $page_id ) ) {
			return;
		}

		AddressFields::enqueue();

		wp_enqueue_style( 'woap-registration', WOAP_PLUGIN_URL . 'assets/css/registration.css', array(), WOAP_VERSION );
	}

	/**
	 * Force WooCommerce's registration settings off.
	 *
	 * @return string Always 'no'.
	 */
	public function disable_woocommerce_registration() {
		return 'no';
	}

	/**
	 * Force WordPress's own open registration off.
	 *
	 * Only gates wp-login.php's register screen; the plugin creates its users through
	 * wp_insert_user(), which this does not affect.
	 *
	 * @return string Always '0'.
	 */
	public function disable_wordpress_registration() {
		return '0';
	}

	/**
	 * Point visitors at the registration page from the My Account login form.
	 *
	 * WooCommerce's register column is gone, so without this the login screen offers
	 * no way for a new organization to sign up.
	 *
	 * @return void
	 */
	public function render_registration_link() {
		$permalink = self::page_url();

		if ( '' === $permalink ) {
			return;
		}

		printf(
			'<p class="woap-registration-link">%s</p>',
			wp_kses_post(
				sprintf(
					/* translators: 1: opening link tag, 2: closing link tag, 3: the organization noun for the site's mode. */
					__( 'New here? %1$sRegister your %3$s%2$s to start ordering.', 'woo-organization-accounts-pro' ),
					'<a href="' . esc_url( $permalink ) . '">',
					'</a>',
					esc_html( Labels::organization() )
				)
			)
		);
	}

	/**
	 * Render whichever form this request calls for.
	 *
	 * @return string Markup.
	 */
	public function render() {
		/*
		 * At render time, not on wp_enqueue_scripts — see Templates::enqueue_theme_parts().
		 * The login-form part in particular loses to the theme's base button rule when it
		 * is asked for too early, which drops the show-password control out of its field.
		 */
		Templates::enqueue_theme_parts( 'woo-mod-login-form', 'woo-page-login-register', 'mod-notices-general' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading an argument this page put in its own redirect, to decide which message to print; nothing is written.
		$pending = isset( $_GET[ self::PENDING_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ self::PENDING_VAR ] ) ) : '';

		if ( '' !== LoginGate::message( $pending ) ) {
			return Templates::get(
				'registration/pending-approval.php',
				array(
					'message'     => LoginGate::message( $pending ),
					'account_url' => wc_get_page_permalink( 'myaccount' ),
					'shop_url'    => wc_get_page_permalink( 'shop' ),
				)
			);
		}

		$token = self::token_from_request();

		if ( '' !== $token ) {
			return $this->render_join_form( $token );
		}

		if ( is_user_logged_in() ) {
			return Templates::get(
				'registration/already-signed-in.php',
				array( 'account_url' => wc_get_page_permalink( 'myaccount' ) )
			);
		}

		$billing = array_fill_keys( AddressFields::keys( AddressFields::BILLING ), '' );

		foreach ( array_keys( $billing ) as $field ) {
			if ( isset( self::$submitted[ 'billing_' . $field ] ) ) {
				$billing[ $field ] = self::$submitted[ 'billing_' . $field ];
			}
		}

		if ( '' === $billing['country'] ) {
			$billing['country'] = WC()->countries->get_base_country();
		}

		return Templates::get(
			'registration/organization-form.php',
			array(
				'errors'    => self::$errors,
				'submitted' => self::$submitted,
				'billing'   => $billing,
				'action'    => self::REGISTER_ACTION,
				'honeypot'  => self::HONEYPOT_FIELD,
			)
		);
	}

	/**
	 * Render the invitation acceptance screen for a token.
	 *
	 * @param string $token Raw token from the link.
	 * @return string Markup.
	 */
	private function render_join_form( $token ) {
		$invitation = InvitationRepository::find_by_token( $token );

		if ( null === $invitation || ! $invitation->is_acceptable() ) {
			return Templates::get(
				'registration/invitation-invalid.php',
				array( 'message' => Invitations::rejection_message() )
			);
		}

		$organization = OrganizationRepository::find( $invitation->get_organization_id() );

		if ( null === $organization ) {
			return Templates::get(
				'registration/invitation-invalid.php',
				array( 'message' => Invitations::rejection_message() )
			);
		}

		$user = get_user_by( 'email', $invitation->get_email() );

		return Templates::get(
			'registration/invitation-form.php',
			array(
				'errors'       => self::$errors,
				'submitted'    => self::$submitted,
				'invitation'   => $invitation,
				'organization' => $organization,
				'has_account'  => $user instanceof \WP_User,
				'logged_in'    => is_user_logged_in(),
				'token'        => $token,
				'action'       => self::JOIN_ACTION,
				'honeypot'     => self::HONEYPOT_FIELD,
			)
		);
	}

	/**
	 * Process a submission, before anything has been sent to the browser.
	 *
	 * Runs on template_redirect rather than inside the shortcode so a successful
	 * registration can redirect, and so the user is logged in before the page that
	 * greets them is rendered.
	 *
	 * @return void
	 */
	public function maybe_process() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only used to decide which handler runs; each verifies its own nonce.
		$action = isset( $_POST['woap_action'] ) ? sanitize_key( wp_unslash( $_POST['woap_action'] ) ) : '';

		if ( 'register' === $action ) {
			$this->process_registration();
			return;
		}

		if ( 'join' === $action ) {
			$this->process_join();
		}
	}

	/**
	 * Handle an organization registration.
	 *
	 * @return void
	 */
	private function process_registration() {
		check_admin_referer( self::REGISTER_ACTION );

		if ( is_user_logged_in() ) {
			return;
		}

		$fields          = self::collect_registration_fields();
		self::$submitted = $fields;
		$errors          = self::validate_registration( $fields );

		if ( $errors->has_errors() ) {
			self::$errors = $errors;
			return;
		}

		$result = self::create_organization( $fields );

		if ( is_wp_error( $result ) ) {
			self::$errors = $result;
			return;
		}

		/*
		 * The cookie is set here rather than through wp_authenticate(), so the login gate
		 * has to be asked directly — signing the new admin in and logging them out again
		 * on their next request would be a worse way of saying "we are reviewing this".
		 */
		$reason = LoginGate::reason_for_status( $result['status'] );

		if ( '' !== $reason ) {
			wp_safe_redirect( self::pending_url( $reason ) );
			exit;
		}

		wp_set_current_user( $result['user_id'] );
		wp_set_auth_cookie( $result['user_id'], true );

		wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
		exit;
	}

	/**
	 * The registration page, asking it to report that an account is awaiting approval.
	 *
	 * A redirect rather than rendering the message straight from the submission, so a
	 * refresh cannot re-post a registration that has already been accepted.
	 *
	 * @param string $reason One of the LoginGate REASON_* codes.
	 * @return string URL.
	 */
	private static function pending_url( $reason ) {
		$page = self::page_url();

		if ( '' === $page ) {
			$page = home_url( '/' );
		}

		return add_query_arg( self::PENDING_VAR, $reason, $page );
	}

	/**
	 * Handle an invitation acceptance.
	 *
	 * @return void
	 */
	private function process_join() {
		check_admin_referer( self::JOIN_ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer() immediately above.
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';

		$invitation = InvitationRepository::find_by_token( $token );

		if ( null === $invitation || ! $invitation->is_acceptable() ) {
			self::$errors = new \WP_Error( 'woap_invitation_unusable', Invitations::rejection_message() );
			return;
		}

		if ( ! self::honeypot_is_empty() ) {
			self::$errors = new \WP_Error( 'woap_spam', Invitations::rejection_message() );
			return;
		}

		$user = get_user_by( 'email', $invitation->get_email() );

		if ( ! $user instanceof \WP_User ) {
			$user = self::create_invited_user( $invitation );

			if ( is_wp_error( $user ) ) {
				self::$errors = $user;
				return;
			}
		} elseif ( get_current_user_id() !== $user->ID ) {
			/*
			 * The address already has an account and nobody is signed into it. Holding
			 * the link is not proof of owning the mailbox any more once an account
			 * exists behind it, so the password is what completes the join.
			 */
			self::$errors = new \WP_Error(
				'woap_sign_in_required',
				sprintf(
					/* translators: %s: the invited email address. */
					__( 'An account already exists for %s. Sign in with it first and follow the invitation link again.', 'woo-organization-accounts-pro' ),
					$invitation->get_email()
				)
			);
			return;
		}

		$accepted = Invitations::accept( $invitation, $user->ID );

		if ( is_wp_error( $accepted ) ) {
			self::$errors = $accepted;
			return;
		}

		/*
		 * An invitation can be sent by an organization that is itself still waiting for
		 * approval, so the same gate applies here. Somebody who was already signed in is
		 * signed out again rather than left holding a session the rule has just closed —
		 * LoginGate would end it on their next request anyway, and doing it here is what
		 * lets the screen explain why.
		 */
		$reason = LoginGate::reason( $user->ID );

		if ( '' !== $reason ) {
			if ( is_user_logged_in() ) {
				wp_logout();
			}

			wp_safe_redirect( self::pending_url( $reason ) );
			exit;
		}

		if ( ! is_user_logged_in() ) {
			wp_set_current_user( $user->ID );
			wp_set_auth_cookie( $user->ID, true );
		}

		wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
		exit;
	}

	/**
	 * Create the account for an invitee who does not have one yet.
	 *
	 * The email address is taken from the invitation rather than from the form: the
	 * invitation is bound to an address, and letting the form supply one would make
	 * the binding decorative.
	 *
	 * @param Invitation $invitation Invitation being redeemed.
	 * @return \WP_User|\WP_Error The new user.
	 */
	private static function create_invited_user( Invitation $invitation ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- The caller verified the nonce before reaching here.
		$first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
		$last_name  = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A password is hashed, never stored or echoed; sanitising it would silently change what the visitor typed.
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- As above.
		$confirm = isset( $_POST['password_confirm'] ) ? (string) wp_unslash( $_POST['password_confirm'] ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		self::$submitted = array(
			'first_name' => $first_name,
			'last_name'  => $last_name,
		);

		$errors = new \WP_Error();
		self::validate_password( $password, $confirm, $errors );

		if ( '' === $first_name ) {
			$errors->add( 'first_name', __( 'Please enter your first name.', 'woo-organization-accounts-pro' ) );
		}

		if ( $errors->has_errors() ) {
			return $errors;
		}

		$user_id = wp_insert_user(
			array(
				'user_login' => $invitation->get_email(),
				'user_email' => $invitation->get_email(),
				'user_pass'  => $password,
				'first_name' => $first_name,
				'last_name'  => $last_name,
				'role'       => Roles::wordpress_role( $invitation->get_role() ),
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		return get_user_by( 'id', $user_id );
	}

	/**
	 * Read and sanitise every field of the registration form.
	 *
	 * @return array Sanitised values, keyed by field name.
	 */
	private static function collect_registration_fields() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- The caller verified the nonce before reaching here.
		$text = static function ( $key ) {
			return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		};

		/*
		 * There is no organization email or phone here, and there were: the billing
		 * block below collects both, WooCommerce marks the billing email required, and
		 * that is the pair every order and every order email is addressed to. Asking
		 * twice put three email fields and three phone fields on one registration form,
		 * and two of each went nowhere.
		 */
		$fields = array(
			'organization_name' => $text( 'organization_name' ),
			'tax_id'            => $text( 'tax_id' ),
			'admin_first_name'  => $text( 'admin_first_name' ),
			'admin_last_name'   => $text( 'admin_last_name' ),
			'admin_email'       => isset( $_POST['admin_email'] ) ? sanitize_email( wp_unslash( $_POST['admin_email'] ) ) : '',
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A password is hashed, never stored or echoed; sanitising it would silently change what the visitor typed.
			'password'          => isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '',
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- As above.
			'password_confirm'  => isset( $_POST['password_confirm'] ) ? (string) wp_unslash( $_POST['password_confirm'] ) : '',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		/*
		 * The billing block comes from WooCommerce's own field set for the chosen
		 * country, so the registration form collects exactly what the checkout will
		 * later insist on. Collecting a fixed list here instead is how an organization
		 * ends up registered with an address its own checkout then rejects.
		 */
		foreach ( AddressFields::posted( AddressFields::BILLING ) as $key => $value ) {
			$fields[ 'billing_' . $key ] = $value;
		}

		return $fields;
	}

	/**
	 * Check a registration submission.
	 *
	 * @param array $fields Sanitised submission. Address values are normalised in place.
	 * @return \WP_Error Errors; empty when the submission is good.
	 */
	private static function validate_registration( array &$fields ) {
		$errors = new \WP_Error();

		if ( ! self::honeypot_is_empty() ) {
			$errors->add( 'spam', __( 'That submission could not be accepted.', 'woo-organization-accounts-pro' ) );

			return $errors;
		}

		if ( '' === $fields['organization_name'] ) {
			$errors->add(
				'organization_name',
				sprintf(
					/* translators: %s: the organization noun for the site's mode, for example "Company". */
					__( 'Please enter the %s name.', 'woo-organization-accounts-pro' ),
					Labels::organization()
				)
			);
		}

		/*
		 * The same rule as the two edit screens, from the same predicate, because a
		 * field registration insists on and the account screen lets you blank again is
		 * not a required field.
		 */
		if ( Organization::tax_id_required() && '' === $fields['tax_id'] ) {
			$errors->add(
				'tax_id',
				__( 'Please enter a VAT number, tax ID or registration number.', 'woo-organization-accounts-pro' )
			);
		}

		if ( ! is_email( $fields['admin_email'] ) ) {
			$errors->add( 'admin_email', __( 'Please enter a valid email address for the account holder.', 'woo-organization-accounts-pro' ) );
		} elseif ( email_exists( $fields['admin_email'] ) ) {
			$errors->add(
				'admin_email',
				sprintf(
					/* translators: %s: URL of the My Account page. */
					wp_kses_post( __( 'An account already exists for that address. <a href="%s">Sign in instead</a>.', 'woo-organization-accounts-pro' ) ),
					esc_url( wc_get_page_permalink( 'myaccount' ) )
				)
			);
		}

		if ( '' === $fields['admin_first_name'] ) {
			$errors->add( 'admin_first_name', __( 'Please enter your first name.', 'woo-organization-accounts-pro' ) );
		}

		self::validate_password( $fields['password'], $fields['password_confirm'], $errors );
		self::validate_billing( $fields, $errors );

		/**
		 * Filters the errors found in an organization registration.
		 *
		 * @since 0.1.0
		 *
		 * @param \WP_Error $errors The errors so far.
		 * @param array     $fields The sanitised submission.
		 */
		return apply_filters( 'woo_org_accounts_registration_errors', $errors, $fields );
	}

	/**
	 * Check the billing address a registration supplied.
	 *
	 * The same checks the checkout will run, because they are literally the same code.
	 * The country is checked against what the shop actually sells to first, which is a
	 * narrower question than "is this a country" and the one that matters here: an
	 * organization registered outside the shop's delivery area could never order.
	 *
	 * Values are normalised in place, so what gets stored is what WooCommerce would
	 * have stored — a formatted postcode, a state as its code.
	 *
	 * @param array     $fields Sanitised submission. Billing values normalised in place.
	 * @param \WP_Error $errors Errors to add to.
	 * @return void
	 */
	private static function validate_billing( array &$fields, \WP_Error $errors ) {
		$address = array();
		$prefix  = AddressFields::BILLING . '_';

		foreach ( $fields as $key => $value ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				$address[ substr( $key, strlen( $prefix ) ) ] = $value;
			}
		}

		$allowed = WC()->countries->get_allowed_countries();

		if ( ! isset( $allowed[ $address['country'] ] ) ) {
			$errors->add( $prefix . 'country', __( 'Please choose a country the shop delivers to.', 'woo-organization-accounts-pro' ) );

			return;
		}

		AddressFields::validate( AddressFields::BILLING, $address, $errors );

		foreach ( $address as $key => $value ) {
			$fields[ $prefix . $key ] = $value;
		}
	}

	/**
	 * Check a chosen password.
	 *
	 * @param string    $password Password.
	 * @param string    $confirm  Repeated password.
	 * @param \WP_Error $errors   Errors to add to.
	 * @return void
	 */
	private static function validate_password( $password, $confirm, \WP_Error $errors ) {
		if ( strlen( $password ) < 8 ) {
			$errors->add( 'password', __( 'Please choose a password of at least 8 characters.', 'woo-organization-accounts-pro' ) );

			return;
		}

		if ( $password !== $confirm ) {
			$errors->add( 'password_confirm', __( 'The two passwords do not match.', 'woo-organization-accounts-pro' ) );
		}
	}

	/**
	 * Whether the honeypot field came back empty, as a person would leave it.
	 *
	 * @return bool True when the submission looks human.
	 */
	private static function honeypot_is_empty() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The caller verified the nonce before reaching here.
		$value = isset( $_POST[ self::HONEYPOT_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::HONEYPOT_FIELD ] ) ) : '';

		return '' === trim( $value );
	}

	/**
	 * Create the user, the organization and the first membership.
	 *
	 * The three writes have to succeed together. There is no transaction to lean on —
	 * wp_insert_user() spans several tables and MySQL's DDL would end one anyway — so
	 * the user is deleted again if the organization or the membership cannot be
	 * written. A user with no organization cannot buy and cannot be repaired from the
	 * frontend; leaving one behind would be worse than reporting the failure.
	 *
	 * @param array $fields Validated submission.
	 * @return array|\WP_Error Map with user_id and organization_id, or an error.
	 */
	private static function create_organization( array $fields ) {
		$user_id = wp_insert_user(
			array(
				'user_login' => $fields['admin_email'],
				'user_email' => $fields['admin_email'],
				'user_pass'  => $fields['password'],
				'first_name' => $fields['admin_first_name'],
				'last_name'  => $fields['admin_last_name'],
				'role'       => Roles::ROLE_ORG_ADMIN,
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$organization = new Organization();
		$organization->set_props(
			array(
				'name'                  => $fields['organization_name'],
				'tax_id'                => $fields['tax_id'],
				'status'                => Settings::get( 'require_approval', true ) ? Organization::STATUS_PENDING : Organization::STATUS_ACTIVE,
				'allow_custom_shipping' => (bool) Settings::get( 'default_allow_custom_shipping', true ),
			)
		);

		$address = array();

		foreach ( $fields as $key => $value ) {
			if ( 0 === strpos( $key, 'billing_' ) ) {
				$address[ substr( $key, strlen( 'billing_' ) ) ] = $value;
			}
		}

		/*
		 * A shop that hides the company or email billing field does not collect it
		 * above, so the rest of the form fills it in: an invoice with no company name
		 * and no address to send it to is not much of an invoice. The account holder's
		 * own address is the fallback for the second, because it is the one address on
		 * this form that is always present and always real.
		 *
		 * There is no phone fallback. A shop that hides the checkout phone field has
		 * decided it does not want phone numbers, and inventing one from another field
		 * to fill a column would be exactly the invented input this plugin refuses
		 * elsewhere.
		 */
		foreach ( array(
			'company' => 'organization_name',
			'email'   => 'admin_email',
		) as $field => $source ) {
			if ( '' === trim( (string) ( $address[ $field ] ?? '' ) ) ) {
				$address[ $field ] = $fields[ $source ];
			}
		}

		$organization->set_billing_address( $address );

		$organization_id = OrganizationRepository::save( $organization );

		if ( 0 === $organization_id ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $user_id );

			return new \WP_Error( 'woap_registration_failed', __( 'Registration failed. Please try again.', 'woo-organization-accounts-pro' ) );
		}

		$member = new Member();
		$member->set_props(
			array(
				'organization_id' => $organization_id,
				'user_id'         => $user_id,
				'role'            => Member::ROLE_ADMIN,
				'status'          => Member::STATUS_ACTIVE,
			)
		);

		if ( 0 === MemberRepository::save( $member ) ) {
			OrganizationRepository::delete( $organization_id );

			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $user_id );

			return new \WP_Error( 'woap_registration_failed', __( 'Registration failed. Please try again.', 'woo-organization-accounts-pro' ) );
		}

		/**
		 * Fires after an organization and its first admin have been created.
		 *
		 * @since 0.1.0
		 *
		 * @param Organization $organization The new organization.
		 * @param Member       $member       Its first admin.
		 */
		do_action( 'woo_org_accounts_organization_registered', $organization, $member );

		return array(
			'user_id'         => $user_id,
			'organization_id' => $organization_id,
			'status'          => $organization->get_status(),
		);
	}

	/**
	 * The invitation token this request carries, if any.
	 *
	 * @return string Raw token, or an empty string.
	 */
	private static function token_from_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A token in a link is not a state change; it is validated against the database before anything happens.
		if ( isset( $_GET[ Invitations::QUERY_VAR ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
			return sanitize_text_field( wp_unslash( $_GET[ Invitations::QUERY_VAR ] ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reading the token back out of a submission that check_admin_referer() has already verified.
		if ( isset( $_POST['token'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- As above.
			return sanitize_text_field( wp_unslash( $_POST['token'] ) );
		}

		return '';
	}

	/**
	 * Create the registration page, once, on activation.
	 *
	 * @return int The page ID, or 0 when one could not be created.
	 */
	public static function create_page() {
		$existing = absint( Settings::get( 'registration_page_id', 0 ) );

		if ( $existing > 0 && 'page' === get_post_type( $existing ) ) {
			return $existing;
		}

		$page_id = wp_insert_post(
			array(
				'post_title'     => __( 'Register your organization', 'woo-organization-accounts-pro' ),
				'post_name'      => 'organization-registration',
				'post_content'   => '<!-- wp:shortcode -->[' . self::SHORTCODE . ']<!-- /wp:shortcode -->',
				'post_status'    => 'publish',
				'post_type'      => 'page',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			)
		);

		if ( is_wp_error( $page_id ) || 0 === $page_id ) {
			return 0;
		}

		$settings                         = Settings::get_settings();
		$settings['registration_page_id'] = $page_id;
		update_option( Settings::OPTION_KEY, $settings, false );

		return $page_id;
	}
}
