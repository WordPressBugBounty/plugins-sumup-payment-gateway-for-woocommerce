<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class to manage onboarding connection
 */
class WC_Sumup_Onboarding {
	/**
	 * Request timeout in seconds for onboarding API calls.
	 *
	 * @var int
	 */
	private const REQUEST_TIMEOUT = 30;

	/**
	 * Undocumented variable
	 *
	 * @var string
	 */
	public $plugin_type = 'WOOCOMMERCE_V1';

	/**
	 * Undocumented variable
	 *
	 * @var string
	 */
	public $website_url;

	/**
	 * Undocumented variable
	 *
	 * @var string
	 */
	public $business_name;

	/**
	 * Construct.
	 */
	public function __construct() {
		$this->website_url   = untrailingslashit( home_url() );
		$this->business_name = get_bloginfo( 'name' );
	}

	/**
	 * Init ajax request
	 *
	 * @return void
	 */
	public function init_ajax_request() {
		add_action( 'wp_ajax_sumup_connect', array( $this, 'sumup_connect' ) );
		add_action( 'wp_ajax_sumup_google_pay', array( $this, 'sumup_google_pay' ) );
	}

	/**
	 * Sumup connect
	 *
	 * @return void
	 */
	public function sumup_connect() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error(
				array(
					'detail' => __( 'Sorry, you are not allowed to manage SumUp settings.', 'sumup-payment-gateway-for-woocommerce' ),
				),
				403
			);
		}

		if ( ! sumup_is_public_hostname() ) {
			wp_send_json_error(
				array(
					'detail' => sumup_get_onboarding_host_warning_message(),
				),
				422
			);
		}

		$nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'sumup-settings-nonce' ) ) {
			wp_send_json_error(
				array(
					'detail' => __( 'Sorry, request not authorized.', 'sumup-payment-gateway-for-woocommerce' ),
				),
				403
			);
		}

		$response      = $this->request_connection();
		$response_data = json_decode( $response, true );

		if ( is_array( $response_data ) && ! empty( $response_data['id'] ) ) {
			$connection_id = sanitize_text_field( $response_data['id'] );
			sumup_store_pending_connection_id( $connection_id );
		}

		delete_option( 'sumup_google_pay_domain_status' );

		wp_send_json( json_decode( $response, true ) );
	}

	/**
	 * Register the current domain with SumUp for Google Pay.
	 *
	 * @return void
	 */
	public function sumup_google_pay() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'detail' => __( 'Sorry, you are not allowed to manage SumUp settings.', 'sumup-payment-gateway-for-woocommerce' ) ), 403 );
		}
		if ( 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			wp_send_json_error( array( 'detail' => __( 'Use POST to register a Google Pay domain.', 'sumup-payment-gateway-for-woocommerce' ) ), 405 );
		}
		$nonce = isset( $_POST['nonce'] ) && is_string( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'sumup-settings-nonce' ) ) {
			wp_send_json_error( array( 'detail' => __( 'Sorry, request not authorized.', 'sumup-payment-gateway-for-woocommerce' ) ), 403 );
		}
		if ( ! is_ssl() || ! sumup_is_public_hostname() ) {
			wp_send_json_error( array( 'detail' => __( 'Google Pay domain registration requires a publicly accessible HTTPS store.', 'sumup-payment-gateway-for-woocommerce' ) ), 422 );
		}
		$settings = sumup_maybe_migrate_merchant_code( sumup_get_gateway_settings() );
		if ( ! sumup_gateway_is_configured( $settings ) ) {
			wp_send_json_error( array( 'detail' => __( 'Connect your SumUp account before continuing with Google Pay.', 'sumup-payment-gateway-for-woocommerce' ) ), 400 );
		}
		if ( ! sumup_should_offer_google_pay( $settings ) ) {
			wp_send_json_error( array( 'detail' => __( 'Enable Google Pay and save your changes before continuing.', 'sumup-payment-gateway-for-woocommerce' ) ), 400 );
		}
		$merchant_code = sanitize_text_field( (string) ( $settings['merchant_id'] ?? '' ) );
		$domain        = sumup_get_site_hostname();
		if ( '' === $merchant_code || '' === $domain ) {
			wp_send_json_error( array( 'detail' => __( 'Unable to determine the merchant code or domain for Google Pay registration.', 'sumup-payment-gateway-for-woocommerce' ) ), 400 );
		}
		$success = array( 'detail' => __( 'Your domain has been registered with SumUp for Google Pay.', 'sumup-payment-gateway-for-woocommerce' ) );
		if ( 'registered' === sumup_get_google_pay_domain_status() ) {
			wp_send_json_success( $success );
		}
		$context  = array(
			'flow'          => 'google_pay_domain_registration',
			'merchant_code' => $merchant_code,
			'domain'        => $domain,
		);
		$token    = Wc_Sumup_Access_Token::get( $settings['client_id'] ?? '', $settings['client_secret'] ?? '', $settings['api_key'] ?? '', false, $context );
		$response = empty( $token['access_token'] ) ? new WP_Error( 'missing_token', 'Unable to resolve access token.' ) : wp_remote_post(
			sprintf( 'https://api.sumup.com/v0.1/wallet-keys/merchants/%s/domains', rawurlencode( $merchant_code ) ),
			array(
				'timeout'     => self::REQUEST_TIMEOUT,
				'redirection' => 0,
				'headers'     => sumup_get_api_request_headers(
					array(
						'Content-Type'    => 'application/json',
						'Idempotency-Key' => wp_generate_uuid4(),
					),
					$token['access_token']
				),
				'body'        => wp_json_encode(
					array(
						'domain'     => $domain,
						'walletType' => 'GOOGLE_PAY',
					)
				),
			)
		);
		$code     = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
		if ( ! in_array( $code, array( 200, 201 ), true ) ) {
			WC_SUMUP_LOGGER::log( 'Google Pay domain registration failed.', array_merge( $context, array( 'http_code' => $code ) ), 'warning' );
			wp_send_json_error( array( 'detail' => __( 'Unable to register your domain with SumUp for Google Pay. Check domain setup in SumUp Dashboard > Settings > For developers > Payment wallets, then try again.', 'sumup-payment-gateway-for-woocommerce' ) ), 502 );
		}
		$current = sumup_get_gateway_settings();
		if ( $merchant_code !== ( $current['merchant_id'] ?? '' ) || $domain !== sumup_get_site_hostname() || ! sumup_gateway_is_configured( $current ) ) {
			wp_send_json_error( array( 'detail' => __( 'Your SumUp account or store domain changed. Reload the settings and try again.', 'sumup-payment-gateway-for-woocommerce' ) ), 409 );
		}
		update_option(
			'sumup_google_pay_domain_status',
			array(
				'status'        => 'registered',
				'merchant_code' => $merchant_code,
				'domain'        => $domain,
			),
			false
		);
		wp_send_json_success( $success );
	}

	/**
	 * Request connection
	 *
	 * @return object
	 */
	public function request_connection() {
		$return_url = add_query_arg(
			'validate_settings',
			'true',
			sumup_get_gateway_setup_link()
		);

		$data = array(
			'plugin_type'    => 'WOOCOMMERCE_V1',
			'plugin_version' => WC_SUMUP_VERSION,
			'website'        => $this->website_url,
			'return_url'     => $return_url,
			'flow_version'   => 2,
			'business_data'  => array(
				'business_name' => $this->business_name,
			),
		);

		$data = wp_json_encode( $data, JSON_UNESCAPED_SLASHES );

		WC_SUMUP_LOGGER::log( 'Onboarding - function request_connection - request: ' . $data );

		$response = wp_remote_post(
			'https://api.sumup.com/online-payments-plugin/connections',
			array(
				'timeout'     => self::REQUEST_TIMEOUT,
				'redirection' => 0,
				'headers'     => sumup_get_api_request_headers(
					array(
						'Idempotency-Key' => wp_generate_uuid4(),
						'Content-Type'    => 'application/json',
					)
				),
				'body'        => $data,
			)
		);

		if ( is_wp_error( $response ) ) {
			WC_SUMUP_LOGGER::log(
				'Onboarding request_connection failed.',
				array(
					'error' => sanitize_text_field( $response->get_error_message() ),
				),
				'error'
			);

			return wp_send_json_error(
				array(
					'detail' => 'An error occurred during onboarding.',
				),
				502
			);
		}

		$response_http_code = wp_remote_retrieve_response_code( $response );
		$response_body      = wp_remote_retrieve_body( $response );
		$response_filtered  = json_decode( $response_body, true );

		if ( ! is_array( $response_filtered ) ) {
			$response_filtered = array();
		}

		if ( in_array( $response_http_code, array( 200, 201 ), true ) ) {
			$encoded_response = wp_json_encode( $this->maskuuidForLogs( $response_filtered ), JSON_UNESCAPED_SLASHES );
			WC_SUMUP_LOGGER::log( 'Onboarding function request_connection - response: ' . $encoded_response );
			return $response_body;
		}

		$encoded_response = array(
			'http_response_code' => $response_http_code,
			'title'              => isset( $response_filtered['title'] ) ? $response_filtered['title'] : '',
			'status'             => isset( $response_filtered['status'] ) ? $response_filtered['status'] : '',
			'detail'             => isset( $response_filtered['detail'] ) ? $response_filtered['detail'] : '',
		);

		WC_SUMUP_LOGGER::log(
			'Onboarding function request_connection - response: ' . wp_json_encode( $encoded_response, JSON_UNESCAPED_SLASHES ),
			array(),
			'warning'
		);

		return wp_send_json_error(
			array(
				'detail' => ! empty( $encoded_response['detail'] ) ? $encoded_response['detail'] : 'An error occurred during onboarding.',
			),
			$response_http_code > 0 ? $response_http_code : 502
		);
	}

	/**
	 * Onboarding template
	 *
	 * @return void
	 */
	public function render_setup_screen() {
		wp_enqueue_script( 'sumup-settings' );
		wp_enqueue_style( 'sumup-settings' );

		$sumup_settings         = get_option( 'woocommerce_sumup_settings', array() );
		$has_connection_details = sumup_gateway_has_connection_details( $sumup_settings );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only failure-banner selection; this value never validates or changes connection settings.
		$validate_settings = isset( $_GET['validate_settings'] ) ? sanitize_text_field( wp_unslash( $_GET['validate_settings'] ) ) : '';

		if ( $has_connection_details && 'false' === $validate_settings ) {
			include WC_SUMUP_PLUGIN_PATH . '/templates/onboarding-failed-message.php';
		}

		include WC_SUMUP_PLUGIN_PATH . '/templates/onboarding.php';
	}

	private function maskuuidForLogs( $responseFiltered ) {
		if ( isset( $responseFiltered['id'] ) ) {
			$parts = explode( '-', $responseFiltered['id'] );
			if ( ! empty( $parts[0] ) ) {
				$masked_id              = $parts[0] . '-****-****-****-************';
				$responseFiltered['id'] = $masked_id;
			}
		}

		if ( isset( $responseFiltered['redirect_url'] ) ) {
			$responseFiltered['redirect_url'] = preg_replace(
				'/connection_id=([a-f0-9\-]+)/i',
				'connection_id=' . $masked_id,
				$responseFiltered['redirect_url']
			);
		}

		return $responseFiltered;
	}
}
