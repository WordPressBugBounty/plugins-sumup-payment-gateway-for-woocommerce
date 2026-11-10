<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sumup_API_Connection_Website_Handler extends Sumup_Api_Handler {


	public function __construct() {
		add_filter( 'sumup_api_handlers', array( $this, 'add_handlers' ) );
	}

	/**
	 * Get the posted data in the checkout.
	 *
	 * @return array
	 * @throws Exception
	 */

	public function add_handlers( $handlers ) {
		$handlers['connection_website'] = array(
			'callback' => array( $this, 'handle' ),
			'method'   => 'POST',
		);

		return $handlers;
	}

	/**
	 * Handle the request.
	 */
	public function handle() {
		$result = WC_Sumup_Connection::accept( $this->get_request_data() );
		if ( is_wp_error( $result ) ) {
			$this->send_response( 'error', $result->get_error_message(), array(), 400 );
		}
		// Preserve the legacy callback's response envelope.
		$this->send_response( $result );
	}

	protected function get_request_data() {
		return json_decode( file_get_contents( 'php://input' ), true );
	}
}

new Sumup_API_Connection_Website_Handler();
