<?php
/**
 * Plugin Name: MP Robokassa Receipt2 (Gift Cards)
 * Description: Sends second fiscal receipt for gift-card settlement scenarios via Robokassa.
 * Version: 0.1.0
 * Author: Popravkin Danil
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: mp-robokassa-receipt2
 */

if (!defined('ABSPATH')) {
	exit;
}

final class MP_Robokassa_Receipt2_Plugin {
	public const VERSION = '0.1.0';

	public static function init(): void {
		self::load_dependencies();
		self::register_hooks();
		MP_Robokassa_Receipt2_Logger::log('INFO', 0, 'plugin_init', 'ok', [
			'version' => self::VERSION,
			'enabled' => MP_Robokassa_Receipt2_Settings::is_enabled(),
		]);
	}

	private static function load_dependencies(): void {
		if (!class_exists('MP_Robokassa_Receipt2_Settings')) {
			require_once __DIR__ . '/includes/class-mp-robokassa-receipt2-settings.php';
		}
		if (!class_exists('MP_Robokassa_Receipt2_Logger')) {
			require_once __DIR__ . '/includes/class-mp-robokassa-receipt2-logger.php';
		}
		if (!class_exists('MP_Robokassa_Receipt2_ApiClient')) {
			require_once __DIR__ . '/includes/class-mp-robokassa-receipt2-api-client.php';
		}
		if (!class_exists('MP_Robokassa_Receipt2_OrderLinks')) {
			require_once __DIR__ . '/includes/class-mp-robokassa-receipt2-order-links.php';
		}
		if (!class_exists('MP_Robokassa_Receipt2_ReceiptBuilder')) {
			require_once __DIR__ . '/includes/class-mp-robokassa-receipt2-receipt-builder.php';
		}
		if (is_admin() && !class_exists('MP_Robokassa_Receipt2_Admin')) {
			require_once __DIR__ . '/admin/class-mp-robokassa-receipt2-admin.php';
			MP_Robokassa_Receipt2_Admin::init();
		}
	}

	private static function register_hooks(): void {
		add_action('woocommerce_order_status_completed', [self::class, 'on_order_completed'], 20, 1);
		add_filter('woocommerce_order_actions', [self::class, 'register_order_action']);
		add_action('woocommerce_order_action_mp_rb_receipt2_resend', [self::class, 'on_manual_resend_order_action']);
	}

	/**
	 * Step 3 hook with logging only.
	 *
	 * @param int $order_id
	 * @return void
	 */
	public static function on_order_completed($order_id): void {
		$order_id = (int) $order_id;
		$order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
		if (!$order instanceof WC_Order) {
			MP_Robokassa_Receipt2_Logger::log('ERROR', $order_id, 'order_completed_hook_fired', 'error', [
				'reason' => 'order_not_found',
			]);
			return;
		}

		self::process_order($order, false);
	}

	/**
	 * Add manual resend action into Woo order actions dropdown.
	 *
	 * @param array<string,string> $actions
	 * @return array<string,string>
	 */
	public static function register_order_action($actions): array {
		if (!is_array($actions)) {
			$actions = [];
		}
		$actions['mp_rb_receipt2_resend'] = 'Отправить второй чек Robokassa повторно';
		return $actions;
	}

	/**
	 * Manual retry handler from Woo order action.
	 *
	 * @param WC_Order $order
	 * @return void
	 */
	public static function on_manual_resend_order_action($order): void {
		if (!$order instanceof WC_Order) {
			return;
		}
		$order_id = (int) $order->get_id();

		// Manual retry is explicit; allow re-send by clearing success marker.
		delete_post_meta($order_id, 'mp_rb_receipt2_sent');
		self::process_order($order, true);
	}

	/**
	 * Unified processing flow for automatic and manual execution.
	 *
	 * @param WC_Order $order
	 * @param bool $manual_retry
	 * @return void
	 */
	private static function process_order(WC_Order $order, bool $manual_retry): void {
		$order_id = (int) $order->get_id();

		$already_sent = get_post_meta($order_id, 'mp_rb_receipt2_sent', true);
		if ($already_sent === 'yes' && !$manual_retry) {
			MP_Robokassa_Receipt2_Logger::log('INFO', $order_id, 'process_order_skip_already_sent', 'skip', []);
			return;
		}

		$settings_errors = MP_Robokassa_Receipt2_Settings::validate_for_api();
		if (!empty($settings_errors)) {
			update_post_meta($order_id, 'mp_rb_receipt2_error', implode('; ', $settings_errors));
			MP_Robokassa_Receipt2_Logger::log('ERROR', $order_id, 'process_order_settings_invalid', 'error', [
				'errors' => $settings_errors,
				'manual_retry' => $manual_retry,
			]);
			return;
		}

		$resolved = MP_Robokassa_Receipt2_OrderLinks::resolve_for_order($order);
		if (empty($resolved['is_gift_card_settlement'])) {
			MP_Robokassa_Receipt2_Logger::log('INFO', $order_id, 'process_order_skip_not_settlement', 'skip', [
				'reason' => $resolved['reason'],
				'manual_retry' => $manual_retry,
			]);
			return;
		}

		if (empty($resolved['source_id'])) {
			update_post_meta($order_id, 'mp_rb_receipt2_error', 'Missing source_id');
			MP_Robokassa_Receipt2_Logger::log('ERROR', $order_id, 'process_order_missing_source_id', 'error', [
				'reason' => $resolved['reason'],
				'settlement_amount' => $resolved['settlement_amount'],
				'manual_retry' => $manual_retry,
			]);
			return;
		}

		$receipt_data = MP_Robokassa_Receipt2_ReceiptBuilder::build($order, (float) $resolved['settlement_amount']);
		$items_count = is_array($receipt_data['items']) ? count($receipt_data['items']) : 0;
		if ($items_count < 1) {
			update_post_meta($order_id, 'mp_rb_receipt2_error', 'Empty receipt items');
			MP_Robokassa_Receipt2_Logger::log('ERROR', $order_id, 'process_order_empty_items', 'error', [
				'warnings' => $receipt_data['warnings'],
				'manual_retry' => $manual_retry,
			]);
			return;
		}

		$fields = self::build_api_fields($order, $resolved, $receipt_data);
		$api_result = MP_Robokassa_Receipt2_ApiClient::send_second_receipt($fields, $order_id);

		if (!empty($api_result['ok'])) {
			update_post_meta($order_id, 'mp_rb_receipt2_sent', 'yes');
			update_post_meta($order_id, 'mp_rb_receipt2_id', (string) $api_result['receipt_id']);
			update_post_meta($order_id, 'mp_rb_receipt2_request_id', (string) $api_result['request_id']);
			delete_post_meta($order_id, 'mp_rb_receipt2_error');
			MP_Robokassa_Receipt2_Logger::log('INFO', $order_id, 'process_order_send_success', 'ok', [
				'receipt_id' => $api_result['receipt_id'],
				'request_id' => $api_result['request_id'],
				'status_code' => $api_result['status_code'],
				'items_count' => $items_count,
				'settlement_amount' => $resolved['settlement_amount'],
				'manual_retry' => $manual_retry,
			]);
			if ($manual_retry) {
				$order->add_order_note('Второй чек Robokassa отправлен вручную успешно.');
			}
			return;
		}

		$error_message = is_string($api_result['error']) && $api_result['error'] !== '' ? $api_result['error'] : 'Unknown API error';
		update_post_meta($order_id, 'mp_rb_receipt2_error', $error_message);
		update_post_meta($order_id, 'mp_rb_receipt2_request_id', (string) $api_result['request_id']);
		MP_Robokassa_Receipt2_Logger::log('ERROR', $order_id, 'process_order_send_failed', 'error', [
			'error' => $error_message,
			'request_id' => $api_result['request_id'],
			'status_code' => $api_result['status_code'],
			'response' => $api_result['response'],
			'manual_retry' => $manual_retry,
		]);
		if ($manual_retry) {
			$order->add_order_note('Ошибка ручной отправки второго чека Robokassa: ' . $error_message);
		}
	}

	/**
	 * Build API fields payload for RoboFiscal attach.
	 *
	 * @param WC_Order $order
	 * @param array<string,mixed> $resolved
	 * @param array<string,mixed> $receipt_data
	 * @return array<string,mixed>
	 */
	private static function build_api_fields(WC_Order $order, array $resolved, array $receipt_data): array {
		return [
			'MerchantLogin' => MP_Robokassa_Receipt2_Settings::get_login(),
			'InvoiceID' => (int) $order->get_id(),
			'SourceInvoiceId' => (string) $resolved['source_id'],
			'OutSum' => (string) wc_format_decimal((float) $resolved['settlement_amount'], wc_get_price_decimals()),
			'Receipt' => [
				'items' => isset($receipt_data['items']) && is_array($receipt_data['items']) ? $receipt_data['items'] : [],
				'settlements' => isset($receipt_data['settlements']) && is_array($receipt_data['settlements']) ? $receipt_data['settlements'] : [],
			],
		];
	}
}

add_action('plugins_loaded', [MP_Robokassa_Receipt2_Plugin::class, 'init']);

