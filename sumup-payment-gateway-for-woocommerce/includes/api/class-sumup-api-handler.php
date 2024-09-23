<?php

// Exit if run outside WP.
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Class Sumup_Api_Handler.
 */
class Sumup_Api_Handler
{

	/**
	 * Sumup_Api_Handler constructor.
	 */
	public function __construct()
	{
		add_action('woocommerce_api_sumup_api_handler', array($this, 'handler'));

		// Include API files.
		$this->includes();
	}

	/**
	 * Include all API files.
	 */
	private function includes()
	{
		include_once dirname(__FILE__) . '/handlers/class-sumup-create-checkout.php';
		include_once dirname(__FILE__) . '/handlers/class-sumup-validation-website-handler.php';
		include_once dirname(__FILE__) . '/handlers/class-sumup-connect-website-handler.php';
	}

	/**
	 * Get all the setup handlers.
	 * @return array
	 */
	public function get_handlers()
	{
		return apply_filters('sumup_api_handlers', array());
	}

	/**
	 * Execute the request.
	 */
	public function handler()
	{
		$handlers = $this->get_handlers();

		// Return an error in case action doesn't exists .
		if (!isset($_GET['action']) || empty($_GET['action']) || !array_key_exists($_GET['action'], $handlers)) {
			wp_send_json(
				array(
					'result' => 'error',
					'message' => "Invalid request. Invalid param 'action'.",
				), 500);
		}

		$action = $_GET['action'];
		call_user_func($handlers[$action]['callback']);
	}

	public function send_response($result = 'success', $message = '', $data = array(), $code = 200)
	{
		wp_send_json(
			array(
				'result' => $result,
				'message' => $message,
				'data' => $data,
			), $code);
	}

}
