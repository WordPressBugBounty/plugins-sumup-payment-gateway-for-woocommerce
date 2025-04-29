<?php

if (!defined('ABSPATH')) {
	exit;
}

class Sumup_API_Connection_Website_Handler extends Sumup_Api_Handler
{

	public function __construct()
	{
		add_filter('sumup_api_handlers', array($this, 'add_handlers'));
	}

	/**
	 * Get the posted data in the checkout.
	 *
	 * @return array
	 * @throws Exception
	 */

	public function add_handlers($handlers)
	{
		$handlers['connection_website'] = array(
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

		WC_SUMUP_LOGGER::log( "Receive connect data handler");

		$transient = get_transient('sumup-connection-id-' . sanitize_text_field($post_data['id']));

		if ( empty( $post_data['id'] ) || empty( $transient ) || $post_data['id'] !== $transient ) {
			$reponse_body = array('status' => 'error', 'message' => 'Invalid connection ID');
			$this->send_response($reponse_body['status'],$reponse_body['message'],array() ,400);
		}

		if (!isset($post_data['merchant']['email'])) {
			$reponse_body = array('status' => 'error', 'message' => 'Invalid merchant email');
			$this->send_response($reponse_body['status'],$reponse_body['message'],array() ,400);
		}

		if (!isset($post_data['merchant']['api_key'])) {
			$reponse_body = array('status' => 'error', 'message' => 'Invalid API key');
			$this->send_response($reponse_body['status'],$reponse_body['message'],array() ,400);
		}

		if (!isset($post_data['merchant']['merchant_code'])) {
			$reponse_body = array('status' => 'error', 'message' => 'Invalid merchant code');
			$this->send_response($reponse_body['status'],$reponse_body['message'],array() ,400);
		}

		$settings = get_option('woocommerce_sumup_settings');
		$settings['pay_to_email'] = $post_data['merchant']['email'];
		$settings['api_key'] = $post_data['merchant']['api_key'];
		$settings['merchant_id'] = $post_data['merchant']['merchant_code'];
		update_option('woocommerce_sumup_settings', $settings);

		delete_transient('sumup-connection-id-' . $post_data['id']);

		$reponse_body = array('status' => 'connected');
		$this->send_response($reponse_body);

	}

}

new Sumup_API_Connection_Website_Handler();
