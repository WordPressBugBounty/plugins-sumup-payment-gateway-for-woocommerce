<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * @class    WC_Gateway_SumUp
 * @since    1.0.0
 * @version  1.0.0
 */
class WC_Gateway_SumUp extends \WC_Payment_Gateway
{
	/**
	 * Merchant ID
	 *
	 * @since 2.0
	 */
	protected $merchant_id;

	/**
	 * API Key
	 *
	 * @since 2.0
	 */
	protected $api_key;

	/**
	 * Client ID
	 *
	 * @since 2.0
	 */
	protected $client_id;

	/**
	 * Client secret
	 *
	 * @since 2.0
	 */
	protected $client_secret;

	/**
	 * Installments
	 *
	 * @since 2.0
	 */
	protected $installments_enabled;

	/**
	 * Number of installments
	 *
	 * @since 2.0
	 */
	protected $number_of_installments;

	/**
	 * Merchant mail
	 *
	 * @since 2.o
	 */
	protected $pay_to_email;

	/**
	 * Remove PIX
	 *
	 * @since 2.0
	 */
	protected $enable_pix;

	/**
	 * Currency
	 *
	 * @since 2.0
	 */
	protected $currency;

	/**
	 * Return URL
	 *
	 * @since 2.0
	 */
	protected $return_url;

	/**
	 * Return URL
	 *
	 * @since 2.0
	 */
	protected $open_payment_in_modal;

	/**
	 * Enable webhook priority
	 *
	 * @since 2.0
	 */
	protected $enable_webhook_priority;

	/**
	 * Webhook retry attempts
	 *
	 * @since 2.0
	 */
	protected $webhook_retry_attempts;

	/**
	 * Enable webhook notifications
	 *
	 * @since 2.0
	 */
	protected $enable_webhook_notifications;

	/**
	 * Webhook timeout
	 *
	 * @since 2.0
	 */
	protected $webhook_timeout;
	/**
	 * Constructor.
	 */
	public function __construct()
	{
		/* Init options */
		$this->init_options();

		/* Load the form fields */
		$this->init_form_fields();

		/* Load the settings */
		$this->init_settings();

		/* Load actions */
		$this->init_actions();
	}

	/**
	 * Initialize all options and properties.
	 *
	 * @since    1.0.0
	 * @version  1.0.0
	 */
	public function init_options()
	{
		$this->id = 'sumup';
		$this->method_title = __('SumUp', 'sumup-payment-gateway-for-woocommerce');
		/* translators: %1$s = https://me.sumup.com/, %2$s = https://me.sumup.com/developers */
		$this->method_description = sprintf(__('SumUp works by adding payment fields on the checkout and then sending the details to SumUp for verification. <a href="%1$s" target="_blank">Sign up</a> for a SumUp account. After logging in, <a href="%2$s" target="_blank">get your SumUp account keys</a>.', 'sumup-payment-gateway-for-woocommerce'), 'https://me.sumup.com/', 'https://me.sumup.com/developers');
		$this->has_fields = true;
		$this->supports = array(
			'subscriptions',
			'products',
		);
		$this->title = $this->get_option('title');
		$this->description = $this->get_option('description');
		$this->enabled = 'yes' === $this->get_option('enabled');
		$this->merchant_id = $this->get_option('merchant_id');
		$this->installments_enabled = $this->get_option('enable_installments', false);
		$this->number_of_installments = $this->get_option('number_of_installments', false);
		$this->api_key = $this->get_option('api_key');
		$this->client_id = $this->get_option('client_id');
		$this->client_secret = $this->get_option('client_secret');
		$this->pay_to_email = $this->get_option('pay_to_email');
		$this->enable_pix = $this->get_option('enable_pix');
		$this->currency = get_woocommerce_currency();
		$this->return_url = WC()->api_request_url('wc_gateway_sumup');
		$this->open_payment_in_modal = $this->get_option('open_payment_modal');

		// Advanced webhook settings
		$this->enable_webhook_priority = "yes";
		$this->webhook_retry_attempts = 5;
		$this->enable_webhook_notifications = 'no';
		$this->webhook_timeout = 30;
	}

	/**
	 * Initialize action hooks.
	 *
	 * @since    1.0.0
	 * @version  1.0.0
	 */
	public function init_actions()
	{
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'verify_credential_options'));
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'verify_credentials'));
		add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
		add_action('woocommerce_before_thankyou', array($this, 'add_payment_instructions_thankyou_page'));
		add_action('woocommerce_api_wc_gateway_sumup', array($this, 'webhook'));
		add_action('template_redirect', array($this, 'check_redirect_flow'), 99);
		add_action('process_webhook_order', array($this, 'handle_webhook_order'));
		add_action('process_webhook_order_priority', array($this, 'handle_webhook_order_with_retry'));
		$this->admin_custom_url();
	}

	/**
	 * Add params in the url admin page.
	 * @return void
	 */
	public function admin_custom_url()
	{
		if (
			isset($_GET['page']) && $_GET['page'] === 'wc-settings' &&
			isset($_GET['tab']) && $_GET['tab'] === 'checkout' &&
			isset($_GET['section']) && $_GET['section'] === 'sumup' &&
			!isset($_GET['validate_settings'])
		) {

			$is_valid_onboarding_settings = Wc_Sumup_Credentials::validate();
			// If the params already exist, will don't add again.
			if (!isset($_GET['validate_settings'])) {
				$new_url = add_query_arg([
					'validate_settings' => $is_valid_onboarding_settings ? "true" : "false",
				], admin_url('admin.php?page=wc-settings&tab=checkout&section=sumup'));

				//redirect to new url.
				wp_safe_redirect($new_url);

				exit;
			}
		}
	}

	public function webhook()
	{

		$request_body = file_get_contents('php://input');
		$data = json_decode($request_body, true);

		if (!$data || !isset($data['event_type'])) {
			wp_send_json_error(['message' => 'Invalid data'], 400);
			return;
		}

		// Log to received webhook
		WC_SUMUP_LOGGER::log('Webhook received: ' . json_encode($data, JSON_UNESCAPED_UNICODE));

		$this->enqueue_webhook_with_priority($data);

		wp_send_json_success(['message' => 'Webhook added to queue']);
	}

	/**
	 * Enqueue webhook with high priority and retry logic
	 *
	 * @param array $data Webhook data
	 * @return void
	 */
	private function enqueue_webhook_with_priority($data)
	{
		$checkout_id = $data['id'] ?? '';
		$unique_id = $this->generate_unique_webhook_id($checkout_id);

		$this->schedule_webhook_action($data, $unique_id);
		$this->log_webhook_scheduled($unique_id, $checkout_id);
	}

	/**
	 * Generate unique webhook identifier
	 *
	 * @param string $checkout_id
	 * @return string
	 */
	private function generate_unique_webhook_id($checkout_id)
	{
		return 'sumup_webhook_' . $checkout_id . '_' . microtime(true);
	}

	/**
	 * Schedule webhook action in ActionScheduler
	 *
	 * @param array $data Webhook data
	 * @param string $unique_id Unique identifier
	 * @return void
	 */
	private function schedule_webhook_action($data, $unique_id)
	{
		as_schedule_single_action(
			time() + 60, // Execute in up to 1 minute
			'process_webhook_order_priority',
			[$data, 1, $unique_id], // data + attempt count + unique_id
			'sumup-webhooks-priority', // high priority group
			false, // Allow multiple webhooks - FIX for simultaneous webhooks
			10 // high priority (lower number = max priority)
		);
	}

	/**
	 * Log webhook scheduled event
	 *
	 * @param string $unique_id
	 * @param string $checkout_id
	 * @return void
	 */
	private function log_webhook_scheduled($unique_id, $checkout_id)
	{
		WC_SUMUP_LOGGER::log('Webhook scheduled with high priority. ID: ' . $unique_id . ', Checkout: ' . $checkout_id);
	}

	/**
	 * Webhook to manage order status after SumUp sent a notification
	 *
	 * @return void
	 */
	public function handle_webhook_order($data)
	{
		if (!$this->validate_webhook_data($data)) {
			return;
		}

		$checkout_id = sanitize_text_field($data['id']);
		$event_type = sanitize_text_field($data['event_type']);

		if (!$this->validate_event_type($event_type)) {
			$this->log_invalid_event_type($event_type, $checkout_id);
			return;
		}

		$this->log_webhook_processing($event_type, $checkout_id);

		$access_token = $this->get_access_token();
		if (empty($access_token)) {
			$this->log_access_token_error($checkout_id);
			return;
		}

		$checkout_data = $this->fetch_checkout_data($checkout_id, $access_token);
		if (empty($checkout_data)) {
			$this->log_checkout_data_error($checkout_id);
			return;
		}

		$order = $this->find_order_by_checkout($checkout_data, $checkout_id);
		if ($order === false) {
			return;
		}

		$transaction_code = $checkout_data['transaction_code'] ?? '';
		if (empty($transaction_code)) {
			$this->log_missing_transaction_code($checkout_id);
			return;
		}

		$this->update_order_status($order, $checkout_data, $transaction_code);
	}

	/**
	 * Validate webhook data structure
	 *
	 * @param array $data
	 * @return bool
	 */
	private function validate_webhook_data($data)
	{
		return isset($data['id']) &&
			!empty($data['id']) &&
			isset($data['event_type']) &&
			!empty($data['event_type']);
	}

	/**
	 * Validate event type
	 *
	 * @param string $event_type
	 * @return bool
	 */
	private function validate_event_type($event_type)
	{
		return $event_type === 'CHECKOUT_STATUS_CHANGED';
	}

	/**
	 * Get access token for SumUp API
	 *
	 * @return string
	 */
	private function get_access_token()
	{
		$access_token = Wc_Sumup_Access_Token::get($this->client_id, $this->client_secret, $this->api_key, true);
		return $access_token['access_token'] ?? '';
	}

	/**
	 * Fetch checkout data from SumUp API
	 *
	 * @param string $checkout_id
	 * @param string $access_token
	 * @return array
	 */
	private function fetch_checkout_data($checkout_id, $access_token)
	{
		return Wc_Sumup_Checkout::get($checkout_id, $access_token);
	}

	/**
	 * Find WooCommerce order by checkout data
	 *
	 * @param array $checkout_data
	 * @param string $checkout_id
	 * @return WC_Order|false
	 */
	private function find_order_by_checkout($checkout_data, $checkout_id)
	{
		$checkout_reference = $checkout_data['checkout_reference'] ?? '';
		$order_id = str_replace('WC_SUMUP_', '', $checkout_reference);
		$order_id = intval($order_id);
		$order = wc_get_order($order_id);

		if ($order === false) {
			$this->log_order_not_found($checkout_id);
		}

		return $order;
	}

	/**
	 * Update order status based on payment status
	 *
	 * @param WC_Order $order
	 * @param array $checkout_data
	 * @param string $transaction_code
	 * @return void
	 */
	private function update_order_status($order, $checkout_data, $transaction_code)
	{
		$payment_status = $checkout_data['status'] ?? '';

		// Check if the current status isn't processing or completed.
		if (!$this->should_update_order_status($order)) {
			return;
		}

		if ($payment_status === 'PAID') {
			$this->process_paid_order($order, $transaction_code);
		} elseif ($payment_status === 'FAILED') {
			$this->process_failed_order($order);
		}
	}

	/**
	 * Check if order status should be updated
	 *
	 * @param WC_Order $order
	 * @return bool
	 */
	private function should_update_order_status($order)
	{
		return !in_array($order->get_status(), [
			'processing',
			'completed',
			'refunded',
			'cancelled'
		], true);
	}

	/**
	 * Process paid order
	 *
	 * @param WC_Order $order
	 * @param string $transaction_code
	 * @return void
	 */
	private function process_paid_order($order, $transaction_code)
	{
		$order->update_meta_data('_sumup_transaction_code', $transaction_code);

		// Updates current status unless it's a Virtual AND Downloadable product.
		if ($order->needs_processing()) {
			$order->update_status('processing');
		}

		$this->add_order_note($order, $transaction_code);
		$order->payment_complete($transaction_code);
		$this->execute_payment_complete_hooks($order);
		$order->save();
	}

	/**
	 * Process failed order
	 *
	 * @param WC_Order $order
	 * @return void
	 */
	private function process_failed_order($order)
	{
		$order->update_status('failed');
		$message = __('SumUp payment failed.', 'sumup-payment-gateway-for-woocommerce');
		$order->add_order_note($message);
		$order->save();
	}

	/**
	 * Add order note for successful payment
	 *
	 * @param WC_Order $order
	 * @param string $transaction_code
	 * @return void
	 */
	private function add_order_note($order, $transaction_code)
	{
		$message = sprintf(
			__('SumUp charge complete. Transaction Code: %s', 'sumup-payment-gateway-for-woocommerce'),
			$transaction_code
		);
		$order->add_order_note($message);
	}

	/**
	 * Execute payment complete hooks
	 *
	 * @param WC_Order $order
	 * @return void
	 */
	private function execute_payment_complete_hooks($order)
	{
		do_action('sumup_gateway_payment_complete_from_hook', $order);
		do_action('sumup_gateway_payment_complete', $order);
	}

	// Logging methods
	private function log_invalid_event_type($event_type, $checkout_id)
	{
		WC_SUMUP_LOGGER::log('Invalid event type on Webhook. Event: ' . $event_type . '. Merchant Id: ' . $this->merchant_id . '. Checkout ID: ' . $checkout_id);
	}

	private function log_webhook_processing($event_type, $checkout_id)
	{
		WC_SUMUP_LOGGER::log('Handling Webhook. Event: ' . $event_type . '. Merchant Id: ' . $this->merchant_id . '. Checkout ID: ' . $checkout_id);
	}

	private function log_access_token_error($checkout_id)
	{
		WC_SUMUP_LOGGER::log('Error to try get access token on Webhook. Merchant Id: ' . $this->merchant_id . '. Checkout ID: ' . $checkout_id);
	}

	private function log_checkout_data_error($checkout_id)
	{
		WC_SUMUP_LOGGER::log('Error to try get checkout on Webhook. Merchant Id: ' . $this->merchant_id . '. Checkout ID: ' . $checkout_id);
	}

	private function log_order_not_found($checkout_id)
	{
		WC_SUMUP_LOGGER::log('Order not found on Webhook request from SumUp. Merchant Id: ' . $this->merchant_id . '. Checkout ID: ' . $checkout_id);
	}

	private function log_missing_transaction_code($checkout_id)
	{
		WC_SUMUP_LOGGER::log('Missing transaction code on Webhook request from SumUp. Merchant Id: ' . $this->merchant_id . '. Checkout ID: ' . $checkout_id);
	}

	/**
	 * Handle webhook with retry logic and exponential backoff
	 *
	 * @param array $data Webhook data
	 * @param int $attempt Current attempt number
	 * @return void
	 */
	public function handle_webhook_order_with_retry($data, $attempt = 1)
	{
		$max_attempts = $this->webhook_retry_attempts;
		$checkout_id = $data['id'] ?? '';
		$performance_tracker = $this->start_performance_tracking();

		$this->log_webhook_attempt($attempt, $max_attempts, $checkout_id, $performance_tracker);

		try {
			$result = $this->process_webhook_data($data);

			if ($result['success']) {
				$this->log_webhook_success($checkout_id, $attempt, $performance_tracker);
				return;
			}

			$this->handle_webhook_failure($data, $attempt, $max_attempts, $result);
		} catch (Exception $e) {
			$this->handle_webhook_exception($data, $attempt, $max_attempts, $e, $checkout_id);
		}
	}

	/**
	 * Start performance tracking
	 *
	 * @return string
	 */
	private function start_performance_tracking()
	{
		return date('Y-m-d H:i:s');
	}

	/**
	 * Calculate execution time
	 *
	 * @param string $start_time
	 * @return int
	 */
	private function calculate_execution_time($start_time)
	{
		$end_time = date('Y-m-d H:i:s');
		$start = new DateTime($start_time);
		$end = new DateTime($end_time);
		$interval = $end->diff($start);
		return $interval->s + ($interval->i * 60) + ($interval->h * 3600) + ($interval->days * 86400);
	}

	/**
	 * Handle webhook processing failure
	 *
	 * @param array $data
	 * @param int $attempt
	 * @param int $max_attempts
	 * @param array $result
	 * @return void
	 */
	private function handle_webhook_failure($data, $attempt, $max_attempts, $result)
	{
		$checkout_id = $data['id'] ?? '';

		// Retry logic for non-critical errors
		if ($this->should_retry_webhook($attempt, $max_attempts, $result['critical_error'])) {
			$this->schedule_webhook_retry($data, $attempt + 1);
			return;
		}

		// Maximum attempts reached or critical error occurred
		$this->log_webhook_final_failure($checkout_id, $attempt, $result['error']);
		$this->handle_webhook_final_failure($data, $result['error']);
	}

	/**
	 * Handle webhook processing exception
	 *
	 * @param array $data
	 * @param int $attempt
	 * @param int $max_attempts
	 * @param Exception $e
	 * @param string $checkout_id
	 * @return void
	 */
	private function handle_webhook_exception($data, $attempt, $max_attempts, $e, $checkout_id)
	{
		$this->log_webhook_exception($attempt, $checkout_id, $e->getMessage());

		if ($this->should_retry_webhook($attempt, $max_attempts, false)) {
			$this->schedule_webhook_retry($data, $attempt + 1);
		} else {
			$this->handle_webhook_final_failure($data, $e->getMessage());
		}
	}

	/**
	 * Check if webhook should be retried
	 *
	 * @param int $attempt
	 * @param int $max_attempts
	 * @param bool $is_critical_error
	 * @return bool
	 */
	private function should_retry_webhook($attempt, $max_attempts, $is_critical_error)
	{
		return $attempt < $max_attempts && !$is_critical_error;
	}

	// Performance and retry logging methods
	private function log_webhook_attempt($attempt, $max_attempts, $checkout_id, $start_time)
	{
		WC_SUMUP_LOGGER::log("Processing webhook (attempt {$attempt}/{$max_attempts}). Checkout ID: {$checkout_id} - Initial time: " . $start_time);
	}

	private function log_webhook_success($checkout_id, $attempt, $start_time)
	{
		$execution_time = $this->calculate_execution_time($start_time);
		$end_time = date('Y-m-d H:i:s');
		WC_SUMUP_LOGGER::log("Webhook processed with success on attempt {$attempt}. Checkout ID: {$checkout_id} - End time: " . $end_time . " Total time: " . $execution_time . " seconds");
	}

	private function log_webhook_final_failure($checkout_id, $attempt, $error)
	{
		WC_SUMUP_LOGGER::log("Webhook failed after {$attempt} attempts. Checkout ID: {$checkout_id}. Error: " . $error);
	}

	private function log_webhook_exception($attempt, $checkout_id, $error)
	{
		WC_SUMUP_LOGGER::log("Exception during webhook processing (attempt {$attempt}). Checkout ID: {$checkout_id}. Error: " . $error);
	}

	/**
	 * Process webhook data and return result
	 *
	 * @param array $data Webhook data
	 * @return array Result with success status and error info
	 */
	private function process_webhook_data($data)
	{
		// Validate webhook data structure
		$validation_result = $this->validate_webhook_request($data);
		if (!$validation_result['valid']) {
			return [
				'success' => false,
				'critical_error' => $validation_result['critical'],
				'error' => $validation_result['error']
			];
		}

		$checkout_id = sanitize_text_field($data['id']);

		// Get access token
		$access_token = $this->get_access_token();
		if (empty($access_token)) {
			return [
				'success' => false,
				'critical_error' => false,
				'error' => 'Failed to obtain access token'
			];
		}

		// Fetch checkout data
		$checkout_data = $this->fetch_checkout_data($checkout_id, $access_token);
		if (empty($checkout_data)) {
			return [
				'success' => false,
				'critical_error' => false,
				'error' => 'Failed to obtain checkout data'
			];
		}

		// Process the order
		return $this->process_order_from_checkout($checkout_data, $checkout_id);
	}

	/**
	 * Validate webhook request data
	 *
	 * @param array $data
	 * @return array
	 */
	private function validate_webhook_request($data)
	{
		if (!$this->validate_webhook_data($data)) {
			return [
				'valid' => false,
				'critical' => true,
				'error' => 'Invalid webhook data'
			];
		}

		$event_type = sanitize_text_field($data['event_type']);
		if (!$this->validate_event_type($event_type)) {
			return [
				'valid' => false,
				'critical' => true,
				'error' => 'Invalid event type: ' . $event_type
			];
		}

		return [
			'valid' => true,
			'critical' => false,
			'error' => ''
		];
	}

	/**
	 * Process order from checkout data
	 *
	 * @param array $checkout_data Checkout data from SumUp API
	 * @param string $checkout_id Checkout ID
	 * @return array Result array
	 */
	private function process_order_from_checkout($checkout_data, $checkout_id)
	{
		// Find the order
		$order = $this->find_order_by_checkout($checkout_data, $checkout_id);
		if ($order === false) {
			return [
				'success' => false,
				'critical_error' => true,
				'error' => 'Order not found: ' . $this->extract_order_id($checkout_data)
			];
		}

		// Validate transaction code
		$transaction_code = $checkout_data['transaction_code'] ?? '';
		if (empty($transaction_code)) {
			return [
				'success' => false,
				'critical_error' => false,
				'error' => 'Transaction code is missing'
			];
		}

		// Update order status
		$this->update_order_status($order, $checkout_data, $transaction_code);

		return [
			'success' => true,
			'critical_error' => false,
			'error' => 'Order processed successfully'
		];
	}

	/**
	 * Extract order ID from checkout data
	 *
	 * @param array $checkout_data
	 * @return int
	 */
	private function extract_order_id($checkout_data)
	{
		$checkout_reference = $checkout_data['checkout_reference'] ?? '';
		$order_id = str_replace('WC_SUMUP_', '', $checkout_reference);
		return intval($order_id);
	}

	/**
	 * Schedule webhook retry with exponential backoff
	 *
	 * @param array $data Webhook data
	 * @param int $attempt Next attempt number
	 * @return void
	 */
	private function schedule_webhook_retry($data, $attempt)
	{
		$delay_seconds = $this->calculate_retry_delay($attempt);
		$checkout_id = $data['id'] ?? '';
		$unique_identifiers = $this->generate_retry_identifiers($checkout_id);

		$this->log_webhook_retry_scheduled($delay_seconds, $attempt, $checkout_id);
		$this->schedule_retry_action($data, $attempt, $delay_seconds, $unique_identifiers);
	}

	/**
	 * Calculate retry delay with exponential backoff
	 *
	 * @param int $attempt
	 * @return int Delay in seconds
	 */
	private function calculate_retry_delay($attempt)
	{
		$delay_minutes = pow(2, $attempt - 1);
		return $delay_minutes * 60;
	}

	/**
	 * Generate unique identifiers for retry
	 *
	 * @param string $checkout_id
	 * @return array
	 */
	private function generate_retry_identifiers($checkout_id)
	{
		return [
			'unique_id' => 'sumup_webhook_' . $checkout_id . '_' . microtime(true),
			'unique_group' => 'sumup-webhooks-' . $checkout_id . '-' . microtime(true)
		];
	}

	/**
	 * Schedule retry action in ActionScheduler
	 *
	 * @param array $data
	 * @param int $attempt
	 * @param int $delay_seconds
	 * @param array $unique_identifiers
	 * @return void
	 */
	private function schedule_retry_action($data, $attempt, $delay_seconds, $unique_identifiers)
	{
		as_schedule_single_action(
			time() + $delay_seconds,
			'process_webhook_order_priority',
			[$data, $attempt, $unique_identifiers['unique_id']],
			$unique_identifiers['unique_group'],
			false,
			5 // Medium priority for retries
		);
	}

	/**
	 * Log webhook retry scheduling
	 *
	 * @param int $delay_seconds
	 * @param int $attempt
	 * @param string $checkout_id
	 * @return void
	 */
	private function log_webhook_retry_scheduled($delay_seconds, $attempt, $checkout_id)
	{
		$delay_minutes = $delay_seconds / 60;
		WC_SUMUP_LOGGER::log("Scheduling webhook retry in {$delay_minutes} minutes. Attempt {$attempt}. Checkout ID: {$checkout_id}");
	}

	/**
	 * Handle final webhook failure after all retries
	 *
	 * @param array $data Webhook data
	 * @param string $error Error message
	 * @return void
	 */
	private function handle_webhook_final_failure($data, $error)
	{
		$checkout_id = $data['id'] ?? '';

		// Log crítico
		WC_SUMUP_LOGGER::log("CRITICAL FAILURE: Webhook failed after all attempts. Checkout ID: {$checkout_id}. Error: {$error}");
	}

	/**
	 * Method used on flows with redirect (like 3Ds)
	 */
	public function check_redirect_flow()
	{
		if (!is_checkout()) {
			return;
		}

		if (!isset($_GET['sumup-validate-order'])) {
			return;
		}

		$order_id = (int) $_GET['sumup-validate-order'];
		$order = wc_get_order($order_id);
		if ($order === false) {
			WC_SUMUP_LOGGER::log('Order not found on validation after payment redirect. Merchant Id: ' . $this->merchant_id . '. Order ID: ' . $order_id);
			return;
		}

		$checkout_data = $order->get_meta('_sumup_checkout_data');
		if (!isset($checkout_data['id'])) {
			WC_SUMUP_LOGGER::log('Missed $checkout_data on validation after payment redirect. Merchant Id: ' . $this->merchant_id . '. Order ID: ' . $order_id);
			return;
		}

		$access_token = Wc_Sumup_Access_Token::get($this->client_id, $this->client_secret, $this->api_key);
		$access_token = $access_token['access_token'] ?? '';
		if (empty($access_token)) {
			WC_SUMUP_LOGGER::log('Error to try get access token on validation after payment redirect. Merchant Id: ' . $this->merchant_id . '. Order ID: ' . $order_id);
			return;
		}

		$sumup_checkout = Wc_Sumup_Checkout::get($checkout_data['id'], $access_token);
		if (empty($sumup_checkout)) {
			WC_SUMUP_LOGGER::log('Error to try get checkout on validation after payment redirect. Merchant Id: ' . $this->merchant_id . '. Order ID: ' . $order_id);
			return;
		}

		$payment_status = $sumup_checkout['status'] ?? '';

		if ($payment_status === 'PENDING') {
			$this->check_redirect_flow();
			return;
		}

		if ($payment_status === 'FAILED') {
			$order->update_status('failed');
			add_action('woocommerce_before_checkout_form', array($this, 'redirect_validation_failed_message'));
			return;
		}

		//Verify if the transaction is correct before check status PAID
		$transaction_code = $sumup_checkout['transaction_code'] ?? '';
		if (empty($transaction_code)) {
			WC_SUMUP_LOGGER::log('Missing transaction code on redirect payment flow from SumUp. Checkout data: ' . $checkout_data . '. Merchant Id: ' . $this->merchant_id . '. Checkout ID: ' . $checkout_data['id']);
			wp_redirect($this->get_return_url($order));
			exit;
		}

		if ($payment_status === 'PAID') {
			wp_redirect($this->get_return_url($order));
			exit;
		}
	}

	/**
	 * Redirect validation failed message
	 */
	public function redirect_validation_failed_message()
	{
		$failed_message = sprintf(
			'<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout woocommerce-error">%1$s</div>',
			__('Payment failed, please try again.', 'sumup-payment-gateway-for-woocommerce')
		);
		echo wp_kses_post($failed_message);

?>
		<script>
			if (document.readyState === 'complete') {
				sumUpSubmitOrderAfterRedirect();
			} else {
				window.addEventListener('load', () => {
					sumUpSubmitOrderAfterRedirect();
				});
			}

			function sumUpSubmitOrderAfterRedirect() {
				const submitOrderButton = document.querySelector('form button#place_order');
				if (submitOrderButton) {
					submitOrderButton.click();
				}
			}
		</script>
		<?php
	}

	/**
	 * Initialise gateway settings form fields
	 *
	 * @since   1.0.0
	 * @version 1.0.0
	 */
	public function init_form_fields()
	{
		$this->form_fields = require WC_SUMUP_PLUGIN_PATH . '/includes/class-wc-sumup-settings.php';
	}

	/**
	 * Process the payment.
	 *
	 * @param int $order_id
	 * @since     1.0.0
	 * @version   1.0.0
	 */
	public function process_payment($order_id)
	{
		$order = wc_get_order($order_id);
		$sumup_checkout = $order->get_meta('_sumup_checkout_data');

		$access_token = Wc_Sumup_Access_Token::get($this->client_id, $this->client_secret, $this->api_key);
		if (!isset($access_token['access_token'])) {
			WC_SUMUP_LOGGER::log('Error on request (cURL) to get access token. Merchant Id: ' . $this->merchant_id);
			$message = current_user_can('manage_options') ? 'Error to generate SumUp access token.' : 'Sorry, SumUp is not available. Try again soon.';

			if (!get_option('sumup_valid_credentials')) {
				if (!empty($sumup_checkout) && isset($sumup_checkout['isCheckoutBlocks']) && $sumup_checkout['isCheckoutBlocks']) {
					throw new Exception($message);
				} else {
					return [
						'result' => 'failure',
						'redirect' => false,
						'openModal' => false,
						'messages' => $message,
					];
				}
			}
			return false; // Evita continuar a execução se não houver token
		}

		$sumup_settings = get_option('woocommerce_sumup_settings', []);
		$sumup_settings['sumup_access_token'] = $access_token['access_token'];
		$sumup_settings['sumup_token_fetched_date'] = date('Y/m/d H:i:s');
		update_option('woocommerce_sumup_settings', $sumup_settings);

		if (empty($sumup_checkout) || !isset($sumup_checkout['id'])) {
			$checkout_data = [
				'checkout_reference' => 'WC_SUMUP_' . $order_id,
				'amount' => $order->get_total(),
				'currency' => get_woocommerce_currency(),
				'description' => 'WooCommerce #' . $order_id,
				'redirect_url' => wc_get_checkout_url() . '?sumup-validate-order=' . $order_id,
				'return_url' => WC()->api_request_url('wc_gateway_sumup'),
			];

			if (!empty($this->merchant_id)) {
				$checkout_data['merchant_code'] = $this->merchant_id;
			} elseif (!empty($this->pay_to_email)) {
				$checkout_data['pay_to_email'] = $this->pay_to_email;
			}

			$sumup_checkout = Wc_Sumup_Checkout::create($sumup_settings['sumup_access_token'], $checkout_data);
			if (empty($sumup_checkout) || !isset($sumup_checkout['id'])) {
				$error_message = isset($sumup_checkout['error_code']) ?
					"{$sumup_checkout['error_code']} : {$sumup_checkout['message']}" :
					'Error on request (cURL) to create SumUp checkout ID during request to SumUp.';

				WC_SUMUP_LOGGER::log($error_message);

				$message = current_user_can('manage_options') ? 'Error to generate SumUp checkout ID.' : 'Sorry, SumUp is not available. Try again soon.';
				if (!empty($sumup_checkout) && isset($sumup_checkout['isCheckoutBlocks']) && $sumup_checkout['isCheckoutBlocks']) {
					throw new Exception($message);
				} else {
					return [
						'result' => 'failure',
						'redirect' => false,
						'openModal' => false,
						'messages' => $message,
					];
				}
			}

			$order->add_order_note('SumUp checkout ID: ' . $sumup_checkout['id']);
			$order->update_meta_data('_sumup_checkout_data', $sumup_checkout);
			$order->save();
		}

		/**
		 * Fallback to fill merchant code to "old" users.
		 * Temporary solution while SumUp team check other ways to enable request to get merchant_code.
		 */
		if (empty($this->merchant_id) && isset($sumup_checkout['merchant_code'])) {
			$this->update_option('merchant_id', $sumup_checkout['merchant_code']);
		}

		if (isset($sumup_checkout['id'])) {
			return [
				'result' => 'success',
				'redirect' => $this->get_return_url($order),
				'openModal' => true,
				'checkoutId' => $sumup_checkout['id'],
				'redirectUrl' => $this->get_return_url($order),
				'country' => $order->get_billing_country(),
			];
		}

		$message = 'Error to get checkout ID. Check plugin logs.';
		if (!empty($sumup_checkout) && isset($sumup_checkout['isCheckoutBlocks']) && $sumup_checkout['isCheckoutBlocks']) {
			throw new Exception($message);
		}

		return [
			'result' => 'failure',
			'redirect' => false,
			'openModal' => false,
			'messages' => $message,
		];
	}

	/**
	 * Builds our payment fields area. Initializes the SumUp's Card Widget.
	 *
	 * @since    1.0.0;
	 * @version  1.0.0;
	 */
	public function payment_fields()
	{
		if (!is_checkout()) {
			return;
		}

		if (!get_option('sumup_valid_credentials')) {
			esc_html_e('Error: Merchant account settings are incorrectly configured. Check the plugin settings page.', 'sumup-payment-gateway-for-woocommerce');
			return;
		}

		if (!is_wc_endpoint_url('order-pay')) {
			echo '<p>' . esc_html($this->description) . '</p>';
			$extra_class = $this->open_payment_in_modal === 'yes' ? 'modal' : 'no-modal';

		?>
			<style>
				.wc-sumup-modal {
					position: fixed;
					top: 0;
					bottom: auto;
					left: 0;
					right: 0;
					height: 100%;
					background: #000000bd;
					display: flex;
					justify-content: center;
					align-items: center;
					z-index: 9999;
					overflow: scroll
				}

				.wc-sumup-modal.disabled {
					display: none
				}

				.wc-sumup-modal #sumup-card {
					width: 700px;
					max-width: 90%;
					position: relative;
					max-height: 95%;
					background: #fff;
					border-radius: 16px;
					min-height: 140px
				}

				.wc-sumup-modal #wc-sumup-payment-modal-close {
					position: absolute;
					top: -10px;
					right: -5px;
					border-radius: 100%;
					height: 28px;
					width: 28px;
					display: flex;
					justify-content: center;
					align-items: center;
					color: #000;
					background: #fff;
					border: 1px solid #d8dde1;
					cursor: pointer;
					font-weight: 700
				}

				.wc-sumup-modal div[data-sumup-id=payment_option]>label {
					display: flex !important
				}

				.sumup-boleto-pending-screen {
					border: 1px dashed #000;
					padding: 10px;
					border-radius: 12px
				}

				div[data-testid=scannable-barcode]>img {
					height: 250px !important;
					max-height: 100% !important
				}

				.wc-sumup-modal.no-modal {
					position: relative;
					background: #fff
				}

				.wc-sumup-modal.no-modal #wc-sumup-payment-modal-close {
					display: none
				}

				.wc-sumup-modal section img[class*=' sumup-payment'],
				.wc-sumup-modal section img[class^=sumup-payment] {
					width: auto;
					top: 50%;
					transform: translateY(-55%)
				}
			</style>
			<div id="wc-sumup-payment-modal" class="wc-sumup-modal disabled <?php echo esc_attr($extra_class); ?>">
				<div id="sumup-card">
					<div id="wc-sumup-payment-modal-close">
						<span id="wc-sumup-payment-modal-close-btn">X</span>
					</div>
				</div>
			</div>
		<?php
			return;
		}

		/**
		 * Required fileds to request somethings to SumUp - Refator to meke the first verification more complete.
		 */
		if (empty($this->pay_to_email) && empty($this->merchant_id)) {
			WC_SUMUP_LOGGER::log('Please fill "Login Email" and "Merchant ID" on the plugin settings. Merchant Id: ' . $this->merchant_id);
			$message = current_user_can('manage_options') ? 'Please fill "Login Email" and "Merchant ID" on the plugin settings.' : 'Sorry, SumUp is not available. Try again soon.';
			echo $this->print_error_message($message);
			return;
		}

		$description = $this->get_description();
		if ($description) {
			echo wp_kses_post(wpautop(wptexturize($description)));
		}

		$total = WC_Payment_Gateway::get_order_total();

		$sumup_settings = get_option('woocommerce_sumup_settings', false);
		if (empty($sumup_settings)) {
			$unavaliable_message = sprintf(
				'<p>%s</p>',
				__('Sum up is temporarily unavailable. Please contact site admin for more information.', 'sumup-payment-gateway-for-woocommerce'),
			);

			echo wp_kses_post($unavaliable_message);
			return;
		}

		$access_token = Wc_Sumup_Access_Token::get($this->client_id, $this->client_secret, $this->api_key);
		if (!isset($access_token['access_token'])) {
			WC_SUMUP_LOGGER::log('Error on request (cURL) to get access token. Merchant Id: ' . $this->merchant_id);
			$message = current_user_can('manage_options') ? 'Error to generate SumUp access token.' : 'Sorry, SumUp is not available. Try again soon.';
			echo $this->print_error_message($message);
			return;
		}

		$sumup_settings['sumup_access_token'] = $access_token['access_token'];
		$sumup_settings['sumup_token_fetched_date'] = date('Y/m/d H:i:s');
		update_option('woocommerce_sumup_settings', $sumup_settings);

		$order_id = sanitize_text_field(get_query_var('order-pay'));
		$order = wc_get_order($order_id);
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

			if (!empty($this->merchant_id)) {
				$checkout_data['merchant_code'] = $this->merchant_id;
			}

			if (!empty($this->pay_to_email) && empty($this->merchant_id)) {
				$checkout_data['pay_to_email'] = $this->pay_to_email;
			}

			$sumup_checkout = Wc_Sumup_Checkout::create($sumup_settings['sumup_access_token'], $checkout_data);
			if (empty($sumup_checkout)) {
				WC_SUMUP_LOGGER::log('Error on request (cURL) to create SumUp checkout ID. Merchant Id: ' . $this->merchant_id);
				$message = current_user_can('manage_options') ? 'Error to generate SumUp checkout id.' : 'Sorry, SumUp is not available. Try again soon.';
				echo $this->print_error_message($message);
				return;
			}
			$order->update_meta_data('_sumup_checkout_data', $sumup_checkout);
			$order->save();
		}

		/**
		 * Fallback to fill merchant code to "old" users. Temporary solution while SumUp team check other ways to enable request to get merchant_code.
		 */
		if (empty($this->merchant_id) && isset($sumup_checkout['merchant_code'])) {
			$this->update_option('merchant_id', $sumup_checkout['merchant_code']);
		}

		if (isset($sumup_checkout['id'])) {
			$extra_class = $this->open_payment_in_modal === 'yes' ? 'no-modal' : '';

		?>
			<style>
				.wc-sumup-modal {
					position: fixed;
					top: 0;
					bottom: auto;
					left: 0;
					right: 0;
					height: 100%;
					background: #000000bd;
					display: flex;
					justify-content: center;
					align-items: center;
					z-index: 9999;
					overflow: scroll
				}

				.wc-sumup-modal.disabled {
					display: none
				}

				.wc-sumup-modal #sumup-card {
					width: 700px;
					max-width: 90%;
					position: relative;
					max-height: 95%;
					background: #fff;
					border-radius: 16px;
					min-height: 140px
				}

				.wc-sumup-modal #wc-sumup-payment-modal-close {
					position: absolute;
					top: -10px;
					right: -5px;
					border-radius: 100%;
					height: 28px;
					width: 28px;
					display: flex;
					justify-content: center;
					align-items: center;
					color: #000;
					background: #fff;
					border: 1px solid #d8dde1;
					cursor: pointer;
					font-weight: 700
				}

				.wc-sumup-modal div[data-sumup-id=payment_option]>label {
					display: flex !important
				}

				.sumup-boleto-pending-screen {
					border: 1px dashed #000;
					padding: 10px;
					border-radius: 12px
				}

				div[data-testid=scannable-barcode]>img {
					height: 250px !important;
					max-height: 100% !important
				}

				.wc-sumup-modal.no-modal {
					position: relative;
					background: #fff
				}

				.wc-sumup-modal.no-modal #wc-sumup-payment-modal-close {
					display: none
				}

				.wc-sumup-modal section img[class*=' sumup-payment'],
				.wc-sumup-modal section img[class^=sumup-payment] {
					width: auto;
					top: 50%;
					transform: translateY(-55%)
				}
			</style>
			<div id="wc-sumup-payment-modal" class="wc-sumup-modal disabled <?php echo esc_attr($extra_class); ?>">
				<div id="sumup-card">
					<div id="wc-sumup-payment-modal-close">
						<span id="wc-sumup-payment-modal-close-btn">X</span>
					</div>
				</div>
			</div>

			<script type="text/javascript">
				if (typeof sumup_gateway_params !== 'undefined') {
					sumup_gateway_params.amount = '<?php echo esc_js($total); ?>';
					sumup_gateway_params.checkoutId = '<?php echo esc_js($sumup_checkout['id']); ?>';
					sumup_gateway_params.redirectUrl = '<?php echo esc_js($this->get_return_url($order)) ?>';
					sumup_gateway_params.country = '';
				}

				jQuery(function($) {
					$(document.body).trigger('sumupCardInit');
				});
			</script>
		<?php
		}

		if (isset($sumup_checkout['error_code'])) {
			$error = isset($sumup_checkout['error_message']) ? $sumup_checkout['error_message'] : $sumup_checkout['message'];
			WC_SUMUP_LOGGER::log('SumUp create checkout request: ' . $error . '. Merchant Id: ' . $this->merchant_id);
			$message = current_user_can('manage_options') ? 'Error from response to create checkout on SumUp. Check the logs.' : 'Sorry, SumUp is not available. Try again soon.';
			echo $this->print_error_message($message);
		}
	}

	/**
	 * Template to print error message to user on checkout
	 *
	 * @param string $error_message
	 * @return string
	 */
	private function print_error_message($message)
	{
		$error_message = sprintf(
			'<p>Error: %s</p>',
			__($message, 'sumup-payment-gateway-for-woocommerce')
		);

		return wp_kses_post($error_message);
	}

	/**
	 * Register the JavaScript scripts to the checkout page.
	 *
	 * @since    1.0.0
	 * @version  1.0.0
	 */
	public function payment_scripts()
	{
		/* Add JavaScript only on the checkout page */
		if (!is_cart() && !is_checkout() && !isset($_GET['pay_for_order'])) {
			return;
		}

		/* Add JavaScript only if the plugin is enabled */
		if (!$this->enabled) {
			return;
		}

		/* Add JavaScript only if the plugin is set up correctly */
		if (!get_option('sumup_valid_credentials')) {
			return;
		}

		/*
		 * Use the SumUp's SDK for accepting card payments.
		 * Documentation can be found at https://developer.sumup.com/docs/widgets-card
		 */
		wp_enqueue_script('sumup_gateway_card_sdk', 'https://gateway.sumup.com/gateway/ecom/card/v2/sdk.js', array(), WC_SUMUP_VERSION, false);
		wp_register_script('sumup_gateway_front_script', WC_SUMUP_PLUGIN_URL . 'assets/js/sumup-gateway.min.js', array('sumup_gateway_card_sdk'), WC_SUMUP_VERSION, false);
		wp_register_script('sumup_gateway_process_checkout', WC_SUMUP_PLUGIN_URL . 'assets/js/sumup-process-checkout.min.js', array('jquery'), WC_SUMUP_VERSION, true);
		wp_enqueue_script('sumup_gateway_process_checkout');

		$shop_base_country = WC()->countries->get_base_country();
		$supported_countries = array(
			"AT",
			"AU",
			"BE",
			"BG",
			"BR",
			"CH",
			"CL",
			"CO",
			"CY",
			"CZ",
			"DE",
			"DK",
			"EE",
			"ES",
			"FI",
			"FR",
			"GB",
			"GR",
			"HR",
			"HU",
			"IE",
			"IT",
			"LT",
			"LU",
			"LV",
			"MT",
			"NL",
			"NO",
			"PL",
			"PT",
			"RO",
			"SE",
			"SI",
			"SK",
			"US",
		);
		$card_country = in_array($shop_base_country, $supported_countries) ? $shop_base_country : 'null';

		$show_zipcode = $card_country === 'US' ? 'true' : 'false';

		$card_locale = str_replace('_', '-', get_locale());
		$card_supported_locales = array(
			"bg-BG",
			"cs-CZ",
			"da-DK",
			"de-AT",
			"de-CH",
			"de-DE",
			"de-LU",
			"el-CY",
			"el-GR",
			"en-AU",
			"en-GB",
			"en-IE",
			"en-MT",
			"en-US",
			"es-CL",
			"es-ES",
			"et-EE",
			"fi-FI",
			"fr-BE",
			"fr-CH",
			"fr-FR",
			"fr-LU",
			"hu-HU",
			"it-CH",
			"it-IT",
			"lt-LT",
			"lv-LV",
			"nb-NO",
			"nl-BE",
			"nl-NL",
			"pt-BR",
			"pt-PT",
			"pl-PL",
			"sk-SK",
			"sl-SI",
			"sv-SE",
		);
		$card_locale = in_array($card_locale, $card_supported_locales) ? $card_locale : 'en-GB';

		/**
		 * Translators: the following error messages are shown to the end user
		 */
		$error_general = __('Transaction was unsuccessful. Please check the minimum amount or use another valid card.', 'sumup-payment-gateway-for-woocommerce');
		$error_invalid_form = __('Fill in all required details.', 'sumup-payment-gateway-for-woocommerce');

		$installments = "false";
		$number_of_installments = null;
		if ($card_country === 'BR') {
			$installments = $this->installments_enabled === 'yes' ? "true" : $installments;
			$number_of_installments = $this->number_of_installments !== false && $this->number_of_installments !== 'select' ? $this->number_of_installments : $number_of_installments;
		}

		$enable_pix = $this->enable_pix === 'yes' ? 'yes' : 'no';
		$open_payment_in_modal = $this->open_payment_in_modal === 'yes' ? 'yes' : 'no';

		wp_localize_script('sumup_gateway_front_script', 'sumup_gateway_params', array(
			'showZipCode' => "$show_zipcode",
			'showInstallments' => "$installments",
			'maxInstallments' => $number_of_installments,
			'locale' => "$card_locale",
			'country' => '',
			'status' => '',
			'errors' => array(
				'general_error' => "$error_general",
				'invalid_form' => "$error_invalid_form"
			),
			'enablePix' => "$enable_pix",
			'paymentMethod' => '',
			'currency' => "$this->currency",
			'openPaymentInModal' => "$open_payment_in_modal",
			'redirectUrl' => '',
		));

		wp_enqueue_script('sumup_gateway_front_script');
	}

	/**
	 * Verify if SumUp application credentials are valid when saving settings.
	 *
	 * @since    1.0.0
	 * @version  1.0.0
	 */
	public function verify_credentials()
	{
		Wc_Sumup_Credentials::validate();
	}

	public function verify_credential_options()
	{
		$pay_to_email = get_transient('pay_to_email');
		$api_key = get_transient('api_key');
		$merchant_id = get_transient('merchant_id');

		if ($pay_to_email && $api_key && $merchant_id) {
			$settings = get_option('woocommerce_sumup_settings');
			$settings['pay_to_email'] = $pay_to_email;
			$settings['api_key'] = $api_key;
			$settings['merchant_id'] = $merchant_id;
			update_option('woocommerce_sumup_settings', $settings);
		}
	}

	/**
	 * Add Instruction to pay Boleto (BR only) on WooCommerce Thank You page.
	 *
	 * @return void
	 * @since 2.0
	 */
	public function add_payment_instructions_thankyou_page($order_id)
	{
		$order = wc_get_order($order_id);
		if ($order === false) {
			WC_SUMUP_LOGGER::log('Order not found on Thank You page request from SumUp. Merchant Id: ' . $this->merchant_id . '. Order ID: ' . $order_id);
			return;
		}

		$checkout_data = $order->get_meta('_sumup_checkout_data');
		if (!isset($checkout_data['id'])) {
			WC_SUMUP_LOGGER::log('Missed $checkout_data on Thank You. Merchant Id: ' . $this->merchant_id . '. Order ID: ' . $order_id . ' $checkout_data: ' . $checkout_data);
			return;
		}

		$checkout_id = $checkout_data['id'];

		$access_token = Wc_Sumup_Access_Token::get($this->client_id, $this->client_secret, $this->api_key);
		$access_token = $access_token['access_token'] ?? '';
		if (empty($access_token)) {
			WC_SUMUP_LOGGER::log('Error to try get access token on Thank You page. Merchant Id: ' . $this->merchant_id . '. Order ID: ' . $order_id);
			return;
		}

		$checkout_data = Wc_Sumup_Checkout::get($checkout_id, $access_token);
		if (empty($checkout_data)) {
			WC_SUMUP_LOGGER::log('Error to try get checkout on Thank You page. Merchant Id: ' . $this->merchant_id . '. Order ID: ' . $order_id);
			return;
		}

		$payment_status = $checkout_data['status'] ?? '';
		if ($payment_status === 'FAILED') {
			$order->update_status('failed');
		}
		?>
		<style>
			#sumup-payment-status {
				margin-bottom: 20px;
				border: dashed 1px #d2d2d2;
				padding: 12px;
				border-radius: 4px;
				background: #f6f6f6;
			}
		</style>
		<div id="sumup-payment-status">
			<?php echo esc_html__('Payment Status: ', 'sumup-payment-gateway-for-woocommerce') . esc_html($payment_status); ?>
		</div>
		<?php

		$pix_code = sanitize_text_field($_GET['pix-code'] ?? '');
		$pix_image = sanitize_text_field($_GET['pix-image'] ?? '');

		?>
		<div id="pix-content"></div>
		<img id="pix-img" />

		<style>
			#sumup-boleto-code {
				background: #ececec;
				padding: 4px;
				font-weight: 700
			}
		</style>
		<div id="boleto-content"></div>
		<a id="pdf-boleto" target="_blank" href=""></a>
		<p id="barcode-boleto"></p>

		<script>
			const loadPix = () => {
				const paymentMethod = localStorage.getItem('paymentMethod');

				if (paymentMethod === 'pix' || paymentMethod === "qr_code_pix") {
					const pixContent = document.getElementById('pix-content');
					pixContent.innerHTML = `<h2 class="woocommerce-order-details__title"><?php esc_html_e('Payment instructions', 'sumup-payment-gateway-for-woocommerce'); ?></h2>
						<p><?php esc_html_e('PIX code: ', 'sumup-payment-gateway-for-woocommerce'); ?> <span id="sumup-boleto-code">${localStorage.getItem('pix-content')}</span></p>`;
					const pixImg = document.getElementById('pix-img');
					pixImg.src = localStorage.getItem('qrcode');
					pixImg.alt = "sumup-pix-qr-code";
					pixImg.style.maxWidth = "100%";
					pixImg.style.height = "auto";

				}
			};

			const loadBoleto = () => {
				const paymentMethod = localStorage.getItem('paymentMethod');

				if (paymentMethod === 'boleto') {
					const divBoleto = document.getElementById("boleto-content");
					divBoleto.innerHTML = `<h2 class="woocommerce-order-details__title"><?php esc_html_e('Payment instructions', 'sumup-payment-gateway-for-woocommerce'); ?></h2>`;
					const boletoDownload = document.getElementById('pdf-boleto');
					boletoDownload.text = '<?php esc_html_e('Download Boleto', 'sumup-payment-gateway-for-woocommerce'); ?>';
					boletoDownload.setAttribute("href", localStorage.getItem('boleto-pdf'));
					const elementBarcode = document.getElementById('barcode-boleto');
					const barcode = localStorage.getItem('boleto-barcode');
					elementBarcode.innerHTML = `<?php esc_html_e('Code to pay: ', 'sumup-payment-gateway-for-woocommerce'); ?> <span id="sumup-boleto-code">${barcode}</span>`;
				}
			};

			if (document.readyState === 'complete') {
				loadPix();
				loadBoleto();
			} else {
				window.addEventListener('load', () => {
					loadPix();
					loadBoleto();
				});
			}
		</script>
		<?php

		if (!empty($pix_code) && !empty($pix_image)) {
		?>
			<style>
				#sumup-boleto-code {
					background: #ececec;
					padding: 4px;
					font-weight: 700;
				}

				#sumup-pix-qr-code {
					max-width: 100%;
					height: auto;
				}
			</style>
			<h2 class="woocommerce-order-details__title">
				<?php esc_html_e('Payment instructions', 'sumup-payment-gateway-for-woocommerce'); ?></h2>
			<p><?php esc_html_e('PIX code: ', 'sumup-payment-gateway-for-woocommerce'); ?> <span
					id="sumup-boleto-code"><?php echo esc_html($pix_code); ?></span></p>
			<img id="sumup-pix-qr-code" src="<?php echo esc_attr($pix_image); ?>" alt="sumup-pix-qr-code" style="">
		<?php
		}

		$boleto_code = sanitize_text_field($_GET['boleto-code'] ?? '');
		$boleto_link = sanitize_text_field($_GET['boleto-link'] ?? '');

		if (!empty($boleto_code) && !empty($boleto_link)) {
		?>
			<style>
				#sumup-boleto-code {
					background: #ececec;
					padding: 4px;
					font-weight: 700
				}
			</style>
			<h2 class="woocommerce-order-details__title">
				<?php esc_html_e('Payment instructions', 'sumup-payment-gateway-for-woocommerce'); ?></h2>
			<p><?php esc_html_e('Code to pay: ', 'sumup-payment-gateway-for-woocommerce'); ?> <span
					id="sumup-boleto-code"><?php echo esc_html($boleto_code); ?></span></p>
			<a class="button" href="<?php echo esc_attr($boleto_link); ?>"
				target="_blank"><?php esc_html_e('Download Boleto', 'sumup-payment-gateway-for-woocommerce'); ?></a>
<?php
		}
	}
}
