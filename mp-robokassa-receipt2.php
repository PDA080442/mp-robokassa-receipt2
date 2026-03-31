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
	}

	private static function register_hooks(): void {
		add_action('woocommerce_order_status_completed', [self::class, 'on_order_completed'], 20, 1);
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

		$resolved = MP_Robokassa_Receipt2_OrderLinks::resolve_for_order($order);
		$status = (!empty($resolved['is_gift_card_settlement']) && !empty($resolved['source_id'])) ? 'ok' : 'skip';
		$context = $resolved;

		if (!empty($resolved['is_gift_card_settlement'])) {
			$built = MP_Robokassa_Receipt2_ReceiptBuilder::build($order, (float) $resolved['settlement_amount']);
			$context['preview_items_count'] = is_array($built['items']) ? count($built['items']) : 0;
			$context['preview_total_items_amount'] = $built['total_items_amount'];
			$context['preview_warnings'] = $built['warnings'];
		}

		MP_Robokassa_Receipt2_Logger::log('INFO', $order_id, 'order_completed_hook_fired', $status, $context);
	}
}

add_action('plugins_loaded', [MP_Robokassa_Receipt2_Plugin::class, 'init']);

