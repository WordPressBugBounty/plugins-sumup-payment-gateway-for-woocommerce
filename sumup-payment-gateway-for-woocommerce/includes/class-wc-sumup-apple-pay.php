<?php
/**
 * Apple Pay association and domain registration helpers.
 *
 * @package SumUp_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Apple Pay domain association and registration helpers.
 */
class Wc_Sumup_Apple_Pay {
	/**
	 * Request timeout in seconds for SumUp domain registration requests.
	 */
	private const REQUEST_TIMEOUT = 30;

	/**
	 * Canonical association file name shipped with the plugin.
	 */
	private const ASSOCIATION_FILE_NAME = 'apple-developer-merchantid-domain-association.txt';

	/**
	 * Apple Pay domain registration endpoint.
	 */
	private const REGISTRATION_ENDPOINT = 'https://api.sumup.com/v0.1/wallet-keys/merchants/%s/domains';

	/**
	 * Initialise the request hook that serves the association file.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve_association_file' ), 0 );
	}

	/**
	 * Determine whether Apple Pay should be offered in the payment widget.
	 *
	 * @param array|null $settings Optional gateway settings.
	 * @return bool
	 */
	public static function should_offer_apple_pay( $settings = null ) {
		if ( ! is_array( $settings ) ) {
			$settings = sumup_get_gateway_settings();
		}

		return isset( $settings['enable_apple_pay_domain_registration'] ) && 'yes' === $settings['enable_apple_pay_domain_registration'];
	}

	/**
	 * Get the bundled association file path.
	 *
	 * @return string
	 */
	public static function get_association_file_path() {
		$path_candidates = apply_filters(
			'sumup_apple_pay_association_file_paths',
			array(
				WC_SUMUP_PLUGIN_PATH . '/' . self::ASSOCIATION_FILE_NAME,
				WC_SUMUP_PLUGIN_PATH . '/assets/' . self::ASSOCIATION_FILE_NAME,
			)
		);

		foreach ( (array) $path_candidates as $candidate ) {
			$candidate = is_string( $candidate ) ? $candidate : '';

			if ( '' === $candidate ) {
				continue;
			}

			if ( is_readable( $candidate ) && is_file( $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Read the contents of the association file.
	 *
	 * @return string
	 */
	public static function get_association_file_contents() {
		$file_path = self::get_association_file_path();
		if ( '' === $file_path ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the bundled local file verbatim.
		$contents = file_get_contents( $file_path );
		if ( false === $contents ) {
			return '';
		}

		return (string) $contents;
	}

	/**
	 * Check whether the current request targets the well-known association path.
	 *
	 * @param string|null $request_path Optional path override for tests.
	 * @return bool
	 */
	public static function is_association_request( $request_path = null ) {
		if ( null === $request_path ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The URI is parsed and matched against fixed association-file paths.
			$request_uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
			$request_path = wp_parse_url( $request_uri, PHP_URL_PATH );
		}

		$request_path = is_string( $request_path ) ? untrailingslashit( $request_path ) : '';

		return in_array(
			$request_path,
			array(
				'/.well-known/apple-developer-merchantid-domain-association',
				'/.well-known/apple-developer-merchantid-domain-association.dms',
			),
			true
		);
	}

	/**
	 * Determine whether the current request should serve the association file.
	 *
	 * @param string|null $request_path Optional request path override.
	 * @return bool
	 */
	public static function should_serve_association_file( $request_path = null ) {
		return self::is_association_request( $request_path );
	}

	/**
	 * Serve the association file when the well-known path is requested.
	 *
	 * @return void
	 */
	public static function maybe_serve_association_file() {
		if ( ! self::should_serve_association_file() ) {
			return;
		}

		$contents = self::get_association_file_contents();
		if ( '' === $contents ) {
			WC_SUMUP_LOGGER::log(
				__( 'Apple Pay association file is missing or unreadable.', 'sumup-payment-gateway-for-woocommerce' ),
				array(
					'flow' => 'apple_pay',
				),
				'warning'
			);

			status_header( 404 );
			nocache_headers();
			exit;
		}

		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=us-ascii' );
		header( 'Content-Length: ' . strlen( $contents ) );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The file must be served verbatim for Apple Pay verification.
		echo $contents;
		exit;
	}

	/**
	 * Register the current site domain with SumUp for Apple Pay.
	 *
	 * @param array $log_context Optional log context.
	 * @return bool
	 */
	public static function register_domain( $log_context = array() ) {
		if ( ! self::should_offer_apple_pay() ) {
			return false;
		}

		$settings      = sumup_maybe_migrate_merchant_code( sumup_get_gateway_settings() );
		$merchant_code = sanitize_text_field( (string) ( $settings['merchant_id'] ?? '' ) );
		if ( '' === $merchant_code ) {
			WC_SUMUP_LOGGER::log(
				__( 'Skipping Apple Pay domain registration because merchant code is missing.', 'sumup-payment-gateway-for-woocommerce' ),
				array_merge(
					is_array( $log_context ) ? $log_context : array(),
					array(
						'flow' => 'apple_pay',
					)
				),
				'warning'
			);
			return false;
		}

		$domain = sumup_get_site_hostname();
		if ( '' === $domain || ! sumup_is_public_hostname( $domain ) ) {
			WC_SUMUP_LOGGER::log(
				__( 'Skipping Apple Pay domain registration because the store hostname is not public.', 'sumup-payment-gateway-for-woocommerce' ),
				array_merge(
					is_array( $log_context ) ? $log_context : array(),
					array(
						'flow'   => 'apple_pay',
						'domain' => $domain,
					)
				),
				'warning'
			);
			return false;
		}

		if ( '' === self::get_association_file_contents() ) {
			WC_SUMUP_LOGGER::log(
				__( 'Skipping Apple Pay domain registration because the association file is missing.', 'sumup-payment-gateway-for-woocommerce' ),
				array_merge(
					is_array( $log_context ) ? $log_context : array(),
					array(
						'flow'   => 'apple_pay',
						'domain' => $domain,
					)
				),
				'warning'
			);
			return false;
		}

		$access_token_data = Wc_Sumup_Access_Token::get(
			$settings['client_id'] ?? '',
			$settings['client_secret'] ?? '',
			$settings['api_key'] ?? '',
			true,
			is_array( $log_context ) ? $log_context : array()
		);
		$access_token      = isset( $access_token_data['access_token'] ) ? sanitize_text_field( (string) $access_token_data['access_token'] ) : '';
		if ( '' === $access_token ) {
			WC_SUMUP_LOGGER::log(
				__( 'Skipping Apple Pay domain registration because the access token could not be resolved.', 'sumup-payment-gateway-for-woocommerce' ),
				array_merge(
					is_array( $log_context ) ? $log_context : array(),
					array(
						'flow'          => 'apple_pay',
						'domain'        => $domain,
						'merchant_code' => $merchant_code,
					)
				),
				'warning'
			);
			return false;
		}

		$request_body = wp_json_encode(
			array(
				'domain'     => $domain,
				'walletType' => 'APPLE_PAY',
			)
		);

		$response = wp_remote_post(
			sprintf( self::REGISTRATION_ENDPOINT, rawurlencode( $merchant_code ) ),
			array(
				'timeout'     => self::REQUEST_TIMEOUT,
				'redirection' => 0,
				'headers'     => sumup_get_api_request_headers(
					array(
						'Content-Type'    => 'application/json',
						'Idempotency-Key' => wp_generate_uuid4(),
					),
					$access_token
				),
				'body'        => $request_body,
			)
		);

		$context = array_merge(
			is_array( $log_context ) ? $log_context : array(),
			array(
				'flow'          => 'apple_pay',
				'domain'        => $domain,
				'merchant_code' => $merchant_code,
			)
		);

		if ( is_wp_error( $response ) ) {
			$context['error'] = sanitize_text_field( $response->get_error_message() );
			WC_SUMUP_LOGGER::log( __( 'Apple Pay domain registration request failed.', 'sumup-payment-gateway-for-woocommerce' ), $context, 'warning' );
			return false;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$response_data = json_decode( $response_body, true );

		if ( $response_code >= 200 && $response_code < 300 ) {
			WC_SUMUP_LOGGER::log( __( 'Apple Pay domain registration completed.', 'sumup-payment-gateway-for-woocommerce' ), $context, 'info' );
			return true;
		}

		$context['http_code'] = $response_code;
		$context['error']     = is_array( $response_data )
			? sanitize_text_field( (string) ( $response_data['message'] ?? $response_data['detail'] ?? '' ) )
			: '';
		WC_SUMUP_LOGGER::log( __( 'Apple Pay domain registration returned a non-success response.', 'sumup-payment-gateway-for-woocommerce' ), $context, 'warning' );

		return false;
	}
}
