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
		\add_filter( 'woocommerce_settings_checkout', array( $this, 'onboarding_template' ) );
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
		if ( ! wp_verify_nonce( $_REQUEST['nonce'], 'sumup-settings-nonce' ) ) {
			exit( 'Sorry, request not authorized' );
		}

		$response = $this->request_connection();

		$connection_id = json_decode( $response, true )[ 'id' ];
		set_transient( 'sumup-connection-id-' . $connection_id, $connection_id, 7200 );
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
					CURLOPT_URL => 'https://op-plugin-onboarding.op-live-eks-eu-west-1.sam-app.ro/v1/connections', //https://op-plugin-onboarding.op-dev-eks-eu-west-1.sam-app.ro/v1/connections
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
	public function onboarding_template() {
		if ( empty( $_GET[ 'section' ] ) || 'sumup' !== $_GET[ 'section' ] ) {
			return;
		}

		$is_valid_onboarding_settings = Wc_Sumup_Credentials::validate();

		wp_enqueue_script( 'sumup-settings' );
		wp_enqueue_style( 'sumup-settings' );

		/**
		 * Validate sumup account/connection after redirect from SumUp integrations page
		 */
		if ( ! empty( $_GET[ 'validate_settings' ] ) && $_GET[ 'validate_settings' ] === 'true' ) {

			if ( $is_valid_onboarding_settings ) {
				include_once WC_SUMUP_PLUGIN_PATH . '/templates/onboarding-success-message.php';
			} else {
				include_once WC_SUMUP_PLUGIN_PATH . '/templates/onboarding-failed-message.php';
			}
		}

		/**
		 * Check if important settings already filled out.
		 *
		 * [] If is connected and DO NOT HAVE API Key show to connect? Old clients
		 */
		$sumup_settings = get_option( 'woocommerce_sumup_settings' );
		$is_integrations_settings_filled = ! empty( $sumup_settings['api_key'] ) || ( ! empty( $sumup_settings['client_id'] ) && ! empty( $sumup_settings['client_secret'] ) );
		if ( $is_integrations_settings_filled && $is_valid_onboarding_settings ) {
			return;
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
