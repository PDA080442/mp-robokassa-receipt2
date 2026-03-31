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
		MP_Robokassa_Receipt2_Logger::log('INFO', (int) $order_id, 'order_completed_hook_fired', 'ok', []);
	}
}

add_action('plugins_loaded', [MP_Robokassa_Receipt2_Plugin::class, 'init']);

