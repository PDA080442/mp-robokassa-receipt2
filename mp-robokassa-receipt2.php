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
	}

	private static function load_dependencies(): void {
		// Step 1 skeleton: dependencies will be connected in the next sections.
	}

	private static function register_hooks(): void {
		// Step 1 skeleton: runtime hooks will be connected in later steps.
	}
}

add_action('plugins_loaded', [MP_Robokassa_Receipt2_Plugin::class, 'init']);

