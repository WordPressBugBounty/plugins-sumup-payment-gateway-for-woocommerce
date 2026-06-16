<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class to manage onboarding connection
 */
class WC_Sumup_Onboarding {
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
		$this->website_url = untrailingslashit( get_bloginfo( 'url' ) );
		$this->business_name = get_bloginfo( 'name' );
	}

	/**
	 * Init ajax request
	 *
	 * @return void
	 */
	public function init_ajax_request() {
		add_action( 'wp_ajax_sumup_connect', array( $this, 'sumup_connect' ) );
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

		$nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'sumup-settings-nonce' ) ) {
			wp_send_json_error(
				array(
					'detail' => __( 'Sorry, request not authorized.', 'sumup-payment-gateway-for-woocommerce' ),
				),
				403
			);
		}

		$response = $this->request_connection();
		$response_data = json_decode( $response, true );

		if ( is_array( $response_data ) && ! empty( $response_data['id'] ) ) {
			$connection_id = sanitize_text_field( $response_data['id'] );
			set_transient( 'sumup-connection-id-' . $connection_id, $connection_id, 7200 );
			sumup_store_pending_connection_id( $connection_id );
		}

		echo $response;
		die();
	}

	/**
	 * Request connection
	 *
	 * @return object
	 */
	public function request_connection()
	{

		$data = array(
			'plugin_type' => 'WOOCOMMERCE_V1',
			'plugin_version' => WC_SUMUP_VERSION,
			'website' => $this->website_url,
			'business_data' => array(
				'business_name' => $this->business_name,
			),
		);

		$data = json_encode($data, JSON_UNESCAPED_SLASHES);

		WC_SUMUP_LOGGER::log("Onboarding - function request_connection - request: " . $data);

		try {
			$ch = curl_init();
			curl_setopt_array(
				$ch,
				array(
					CURLOPT_URL => 'https://api.sumup.com/online-payments-plugin/connections',
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_POST => true,
					CURLOPT_POSTFIELDS => $data,
					CURLOPT_HTTPHEADER => array(
						'Idempotency-Key: ' . $this->uuidv4(),
						'Content-Type: application/json',
					),
				)
			);
			$response = curl_exec($ch);
			$response_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_close($ch);

			$responseFiltered = json_decode($response, true);

			if (!is_array($responseFiltered)) {
				$responseFiltered = [];
			}

			if (in_array($response_http_code, [200, 201])) {


				$encondedResponse = json_encode($this->maskuuidForLogs($responseFiltered), JSON_UNESCAPED_SLASHES);

				WC_SUMUP_LOGGER::log("Onboarding function request_connection - response: " . $encondedResponse);

			} else {
				$encondedResponse = array(
					"http_response_code" => $response_http_code,
					"title" => isset($responseFiltered['title']) ? $responseFiltered['title'] : "",
					"status" => isset($responseFiltered['status']) ? $responseFiltered['status'] : "",
					"detail" => isset($responseFiltered['detail']) ? $responseFiltered['detail'] : "",
				);

				$encondedResponse = json_encode($encondedResponse, JSON_UNESCAPED_SLASHES);

				WC_SUMUP_LOGGER::log("Onboarding function request_connection - response: " . $encondedResponse);
			}

			return $response;
		} catch (Exception $e) {
			WC_SUMUP_LOGGER::log("An error occurred during onboarding - message: " . $e->getMessage());

			return wp_send_json_error(
				array(
					'detail' => 'An error occurred during onboarding.',
				),
				422
			);
		}
	}

	/**
	 * Onboarding template
	 *
	 * @return void
	 */
	public function render_setup_screen() {
		wp_enqueue_script( 'sumup-settings' );
		wp_enqueue_style( 'sumup-settings' );

		$sumup_settings = get_option( 'woocommerce_sumup_settings', array() );
		$has_connection_details = sumup_gateway_has_connection_details( $sumup_settings );
		$validate_settings = isset( $_GET['validate_settings'] ) ? sanitize_text_field( wp_unslash( $_GET['validate_settings'] ) ) : '';

		if ( $has_connection_details && 'false' === $validate_settings ) {
			include_once WC_SUMUP_PLUGIN_PATH . '/templates/onboarding-failed-message.php';
		}

		include_once WC_SUMUP_PLUGIN_PATH . '/templates/onboarding.php';
	}

	/**
	 * Generate random Version 4 UUID for connection usage
	 *
	 * @return string
	 */
	private function uuidv4() {
		$data = random_bytes(16);

		$data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
		$data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}

	private function maskuuidForLogs($responseFiltered){
		if (isset($responseFiltered['id'])) {
			$parts = explode('-', $responseFiltered['id']);
			if (!empty($parts[0])) {
				$masked_id = $parts[0] . '-****-****-****-************';
				$responseFiltered['id'] = $masked_id;
			}
		}

		if (isset($responseFiltered['redirect_url'])) {
			$responseFiltered['redirect_url'] = preg_replace(
				'/connection_id=([a-f0-9\-]+)/i',
				'connection_id=' . $masked_id,
				$responseFiltered['redirect_url']
			);
		}

		return $responseFiltered;

	}
}
