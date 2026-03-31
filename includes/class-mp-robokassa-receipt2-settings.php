<?php
if (!defined('ABSPATH')) {
	exit;
}

final class MP_Robokassa_Receipt2_Settings {
	public const OPTION_ENABLED = 'mp_rb_receipt2_enabled';
	public const OPTION_SANDBOX = 'mp_rb_receipt2_sandbox';
	public const OPTION_LOGIN = 'mp_rb_receipt2_login';
	public const OPTION_PASSWORD1 = 'mp_rb_receipt2_password1';
	public const OPTION_DEBUG = 'mp_rb_receipt2_debug';
	public const OPTION_DEFAULT_PAYMENT_MODE = 'mp_rb_receipt2_default_payment_mode';
	public const OPTION_DEFAULT_PAYMENT_SUBJECT = 'mp_rb_receipt2_default_payment_subject';
	public const OPTION_RULES = 'mp_rb_receipt2_rules';

	public static function is_enabled(): bool {
		if (defined('MP_RB_RECEIPT2_ENABLED')) {
			return (bool) MP_RB_RECEIPT2_ENABLED;
		}
		return (bool) get_option(self::OPTION_ENABLED, false);
	}

	public static function is_sandbox(): bool {
		if (defined('MP_RB_RECEIPT2_SANDBOX')) {
			return (bool) MP_RB_RECEIPT2_SANDBOX;
		}
		return (bool) get_option(self::OPTION_SANDBOX, false);
	}

	public static function is_debug(): bool {
		if (defined('MP_RB_RECEIPT2_DEBUG')) {
			return (bool) MP_RB_RECEIPT2_DEBUG;
		}
		return (bool) get_option(self::OPTION_DEBUG, false);
	}

	public static function get_login(): string {
		if (defined('MP_RB_RECEIPT2_LOGIN')) {
			return trim((string) MP_RB_RECEIPT2_LOGIN);
		}
		return trim((string) get_option(self::OPTION_LOGIN, ''));
	}

	public static function get_password1(): string {
		if (defined('MP_RB_RECEIPT2_PASSWORD1')) {
			return trim((string) MP_RB_RECEIPT2_PASSWORD1);
		}
		return trim((string) get_option(self::OPTION_PASSWORD1, ''));
	}

	public static function allowed_payment_modes(): array {
		return [
			'full_payment',
			'full_prepayment',
			'advance',
			'partial_payment',
			'partial_prepayment',
			'credit',
			'credit_payment',
		];
	}

	public static function allowed_payment_subjects(): array {
		return [
			'commodity',
			'excise',
			'job',
			'service',
			'payment',
			'another',
		];
	}

	public static function get_default_payment_mode(): string {
		$value = trim((string) get_option(self::OPTION_DEFAULT_PAYMENT_MODE, 'full_payment'));
		if (!in_array($value, self::allowed_payment_modes(), true)) {
			return 'full_payment';
		}
		return $value;
	}

	public static function get_default_payment_subject(): string {
		$value = trim((string) get_option(self::OPTION_DEFAULT_PAYMENT_SUBJECT, 'commodity'));
		if (!in_array($value, self::allowed_payment_subjects(), true)) {
			return 'commodity';
		}
		return $value;
	}

	/**
	 * Rules schema:
	 * [
	 *   [
	 *     'enabled' => true,
	 *     'priority' => 100,
	 *     'category_ids' => [12, 15],
	 *     'payment_mode' => 'full_payment',
	 *     'payment_subject' => 'commodity'
	 *   ]
	 * ]
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_rules(): array {
		$rules = get_option(self::OPTION_RULES, []);
		if (!is_array($rules)) {
			return [];
		}

		$normalized = [];
		foreach ($rules as $rule) {
			if (!is_array($rule)) {
				continue;
			}

			$enabled = !empty($rule['enabled']);
			$priority = isset($rule['priority']) ? (int) $rule['priority'] : 100;
			$payment_mode = isset($rule['payment_mode']) ? trim((string) $rule['payment_mode']) : '';
			$payment_subject = isset($rule['payment_subject']) ? trim((string) $rule['payment_subject']) : '';

			if (!in_array($payment_mode, self::allowed_payment_modes(), true)) {
				continue;
			}
			if (!in_array($payment_subject, self::allowed_payment_subjects(), true)) {
				continue;
			}

			$category_ids = [];
			if (isset($rule['category_ids']) && is_array($rule['category_ids'])) {
				foreach ($rule['category_ids'] as $cat_id) {
					$cat_id = (int) $cat_id;
					if ($cat_id > 0) {
						$category_ids[] = $cat_id;
					}
				}
			}
			$category_ids = array_values(array_unique($category_ids));
			if (empty($category_ids)) {
				continue;
			}

			$normalized[] = [
				'enabled' => $enabled,
				'priority' => $priority,
				'category_ids' => $category_ids,
				'payment_mode' => $payment_mode,
				'payment_subject' => $payment_subject,
			];
		}

		usort($normalized, static function ($a, $b) {
			return ((int) $b['priority']) <=> ((int) $a['priority']);
		});

		return $normalized;
	}

	/**
	 * @return array<int,string>
	 */
	public static function validate_for_api(): array {
		$errors = [];

		if (!self::is_enabled()) {
			$errors[] = 'Plugin is disabled';
			return $errors;
		}

		if (self::get_login() === '') {
			$errors[] = 'Missing Robokassa login';
		}

		if (self::get_password1() === '') {
			$errors[] = 'Missing Robokassa password1';
		}

		if (!in_array(self::get_default_payment_mode(), self::allowed_payment_modes(), true)) {
			$errors[] = 'Invalid default payment_mode';
		}

		if (!in_array(self::get_default_payment_subject(), self::allowed_payment_subjects(), true)) {
			$errors[] = 'Invalid default payment_subject';
		}

		return $errors;
	}
}

