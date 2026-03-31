<?php
if (!defined('ABSPATH')) {
	exit;
}

final class MP_Robokassa_Receipt2_Logger {
	private const DIR_SLUG = 'mp-robokassa-receipt2';
	private const LEVELS = ['INFO', 'DEBUG', 'ERROR'];

	/**
	 * @param string $level INFO|DEBUG|ERROR
	 * @param int|string $order_id
	 * @param string $action
	 * @param string $status
	 * @param array<string,mixed> $context
	 * @return void
	 */
	public static function log(string $level, $order_id, string $action, string $status = 'ok', array $context = []): void {
		$level = strtoupper(trim($level));
		if (!in_array($level, self::LEVELS, true)) {
			$level = 'INFO';
		}

		// Keep debug verbosity under settings/constant control.
		if ($level === 'DEBUG' && !MP_Robokassa_Receipt2_Settings::is_debug()) {
			return;
		}

		self::ensure_dir_exists();
		$line = self::format_line($level, (string) $order_id, $action, $status, self::sanitize_context($context));
		@file_put_contents(self::current_log_path(), $line, FILE_APPEND);
	}

	/**
	 * @param string $level
	 * @param string $order_id
	 * @param string $action
	 * @param string $status
	 * @param array<string,mixed> $context
	 * @return string
	 */
	private static function format_line(string $level, string $order_id, string $action, string $status, array $context): string {
		$ts = date('Y-m-d H:i:s');
		$ctx_json = '';
		if (!empty($context)) {
			$ctx_json = wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			if (!is_string($ctx_json)) {
				$ctx_json = '';
			}
		}

		return sprintf(
			"[%s] %s action=%s order_id=%s status=%s%s\n",
			$ts,
			$level,
			$action,
			$order_id,
			$status,
			$ctx_json !== '' ? ' context=' . $ctx_json : ''
		);
	}

	/**
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	private static function sanitize_context(array $context): array {
		$sanitized = [];
		foreach ($context as $key => $value) {
			$k = strtolower((string) $key);
			if (self::is_sensitive_key($k)) {
				$sanitized[$key] = self::mask_value($value);
				continue;
			}

			if (is_array($value)) {
				$sanitized[$key] = self::sanitize_context($value);
			} else {
				$sanitized[$key] = $value;
			}
		}
		return $sanitized;
	}

	private static function is_sensitive_key(string $key): bool {
		$needles = ['password', 'pass', 'token', 'secret', 'key', 'signature', 'login'];
		foreach ($needles as $needle) {
			if (strpos($key, $needle) !== false) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param mixed $value
	 * @return string
	 */
	private static function mask_value($value): string {
		$str = is_scalar($value) ? (string) $value : wp_json_encode($value);
		if (!is_string($str) || $str === '') {
			return '***';
		}
		$len = strlen($str);
		if ($len <= 4) {
			return str_repeat('*', $len);
		}
		return substr($str, 0, 2) . str_repeat('*', max(1, $len - 4)) . substr($str, -2);
	}

	private static function ensure_dir_exists(): void {
		$dir = self::log_dir();
		if (is_dir($dir)) {
			return;
		}
		if (function_exists('wp_mkdir_p')) {
			wp_mkdir_p($dir);
		} else {
			@mkdir($dir, 0755, true);
		}
	}

	private static function log_dir(): string {
		$uploads = wp_upload_dir();
		$base = is_array($uploads) && !empty($uploads['basedir']) ? $uploads['basedir'] : WP_CONTENT_DIR . '/uploads';
		return rtrim($base, '/\\') . DIRECTORY_SEPARATOR . self::DIR_SLUG;
	}

	private static function current_log_path(): string {
		return self::log_dir() . DIRECTORY_SEPARATOR . 'receipt2-' . date('Y-m') . '.log';
	}
}

