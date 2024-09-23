<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * API for create checkout on Woocommerce checkout blocks.
 */

class Sumup_API_Create_Chekout_Handler extends Sumup_Api_Handler
{

	public function __construct()
	{
		add_filter('sumup_api_handlers', array($this, 'add_handlers'));
	}

	public function add_handlers($handlers)
	{
		$handlers['create_checkout'] = array(
			'callback' => array($this, 'handle'),
			'method' => 'POST',
		);

		return $handlers;
	}

	/**
	 * Handle the request.
	 */
	public function handle()
	{
		$json_data = file_get_contents('php://input');
		$post_data = json_decode($json_data, true);

		$post_data = json_decode($json_data, true);

		if (!isset($post_data['order_id'])) {
			$reponse_body = array('status' => 'error', 'message' => 'Invalid order_id');
			$this->send_response($reponse_body['status'],$reponse_body['message'],array() ,400);
		}
		$result = $this->create_checkout($post_data['order_id']);

		if($result['status'] == 'error'){
			$this->send_response($result['status'],$result['message'],array(),400);
		}

		$this->send_response($result);

	}

	private function create_checkout($order_id, $is_checkout_blocks = false)
	{

		if (!get_option('sumup_valid_credentials')) {
			$message = __('Merchant account settings are incorrectly configured. Check the plugin settings page.', 'sumup-payment-gateway-for-woocommerce');
			return 	array(
				'status' => 'error',
				'message' => $message,
				'data' => null,
			);
		}

		$sumup_settings = get_option('woocommerce_sumup_settings', false);
		if (empty($sumup_settings)) {
			$unavaliable_message = __('Sumup is temporarily unavailable. Please contact site admin for more information.', 'sumup-payment-gateway-for-woocommerce');

			return 	array(
				'status' => 'error',
				'message' => $unavaliable_message,
				'data' => null,
			);
		}

		if (empty($sumup_settings['pay_to_email']) && empty($sumup_settings['merchant_id'])) {
			WC_SUMUP_LOGGER::log('Please fill "Login Email" and "Merchant ID" on the plugin settings. Merchant Id: ' . $this->merchant_id);
			$message = current_user_can('manage_options') ? 'Please fill "Login Email" and "Merchant ID" on the plugin settings.' : 'Sorry, SumUp is not available. Try again soon.';
			return 	array(
				'status' => 'error',
				'message' => $message,
				'data' => null,
			);
		}

		$order = new WC_Order( $order_id );

		$total = $order->get_total();

		$access_token = Wc_Sumup_Access_Token::get($sumup_settings['client_id'], $sumup_settings['client_secret'], $sumup_settings['api_key']);
		if (!isset($access_token['access_token'])) {
			WC_SUMUP_LOGGER::log('Error on request (cURL) to get access token. Merchant Id: ' . $sumup_settings['merchant_id']);
			$message = current_user_can('manage_options') ? 'Error to generate SumUp access token.' : 'Sorry, SumUp is not available. Try again soon.';

			return 	array(
				'status' => 'error',
				'message' => $message,
				'data' => null,
			);
		}

		$sumup_settings['sumup_access_token'] = $access_token['access_token'];
		$sumup_settings['sumup_token_fetched_date'] = date('Y/m/d H:i:s');
		update_option('woocommerce_sumup_settings', $sumup_settings);

		if ($order === false) {
			echo '<p>' . __('Order ID is not available to make the payment. Try again soon or contact the website support.', 'sumup-payment-gateway-for-woocommerce') . '</p>';
			return;
		}

		$sumup_checkout = $order->get_meta('_sumup_checkout_data');
		if (empty($sumup_checkout)) {
			$checkout_data = array(
				'checkout_reference' => 'WC_SUMUP_' . $order_id,
				'amount' => $total,
				'currency' => get_woocommerce_currency(),
				'description' => 'WooCommerce #' . $order_id,
				'redirect_url' => wc_get_checkout_url() . '?sumup-validate-order=' . $order_id,
				'return_url' => WC()->api_request_url('wc_gateway_sumup'),
			);

			if (!empty($sumup_settings['merchant_id'])) {
				$checkout_data['merchant_code'] = $sumup_settings['merchant_id'];
			}

			if (!empty($sumup_settings['pay_to_email']) && empty($sumup_settings['merchant_id'])) {
				$checkout_data['pay_to_email'] = $sumup_settings['pay_to_email'];
			}

			$sumup_checkout = Wc_Sumup_Checkout::create($sumup_settings['sumup_access_token'], $checkout_data);
			if (empty($sumup_checkout)) {
				WC_SUMUP_LOGGER::log('Error on request (cURL) to create SumUp checkout ID. Merchant Id: ' . $sumup_settings['merchant_id']);
				$message = current_user_can('manage_options') ? 'Error to generate SumUp checkout id.' : 'Sorry, SumUp is not available. Try again soon.';
				return 	array(
					'status' => 'error',
					'message' => $message,
					'data' => null,
				);
			}
			if($is_checkout_blocks){
				$sumup_checkout['isCheckoutBlocks'] = $is_checkout_blocks;
			}

			$order->update_meta_data('_sumup_checkout_data', $sumup_checkout);
			$order->save();
		}

		$gateway = $this->get_sumup_gateway('sumup');

		/**
		 * Fallback to fill merchant code to "old" users. Temporary solution while SumUp team check other ways to enable request to get merchant_code.
		 */
		if (empty($sumup_settings['merchant_id']) && isset($sumup_checkout['merchant_code'])) {
			$gateway->update_option('merchant_id', $sumup_checkout['merchant_code']);
		}

		if (isset($sumup_checkout['id'])) {
			$extra_class = $sumup_settings['open_payment_in_modal'] === 'yes' ? 'no-modal' : '';

			return array(
				"amount" => $total,
				"checkoutId" => $sumup_checkout['id'],
				"country" => $order->get_billing_country()
			);

		}

		if (isset($sumup_checkout['error_code'])) {
			$error = isset($sumup_checkout['error_message']) ? $sumup_checkout['error_message'] : $sumup_checkout['message'];
			WC_SUMUP_LOGGER::log('SumUp create checkout request: ' . $error . '. Merchant Id: ' . $sumup_settings['merchant_id']);
			$message = current_user_can('manage_options') ? 'Error from response to create checkout on SumUp. Check the logs.' : 'Sorry, SumUp is not available. Try again soon.';
			return 	array(
				'status' => 'error',
				'message' => $message,
				'data' => null,
			);
		}
	}

	/**
	 * Get Sumup active gateway.
	 *
	 * @param string $gateway
	 *
	 * @return sumup_Gateway
	 * @throws Exception
	 */
	protected function get_sumup_gateway($gateway)
	{
		$payment_gateways = WC()->payment_gateways()->get_available_payment_gateways();

		if (isset($payment_gateways[$gateway])) {
			return $payment_gateways[$gateway];
		}

		throw new Exception('Sumup payment method not found');
	}

}

new Sumup_API_Create_Chekout_Handler();
