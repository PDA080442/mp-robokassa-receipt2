<?php
if (!defined('ABSPATH')) {
	exit;
}

final class MP_Robokassa_Receipt2_ApiClient {
	/**
	 * Send second receipt attach request to Robokassa.
	 *
	 * @param array<string,mixed> $fields Raw fiscal fields payload (will be JSON-encoded and signed).
	 * @param int|string $order_id
	 * @return array{
	 *   ok:bool,
	 *   status_code:int,
	 *   receipt_id:string,
	 *   request_id:string,
	 *   error:string,
	 *   response:mixed
	 * }
	 */
	public static function send_second_receipt(array $fields, $order_id = 0): array {
		$credentials = self::resolve_credentials();
		$request_id = self::generate_request_id();
		$result = [
			'ok' => false,
			'status_code' => 0,
			'receipt_id' => '',
			'request_id' => $request_id,
			'error' => '',
			'response' => null,
		];

		if ($credentials['login'] === '' || $credentials['password1'] === '') {
			$result['error'] = 'Missing Robokassa credentials';
			return $result;
		}

		$payload = self::build_attach_payload($fields, $credentials['password1']);
		if ($payload === '') {
			$result['error'] = 'Failed to build signed payload';
			return $result;
		}

		$url = self::attach_endpoint($credentials['country']);
		$args = [
			'timeout' => 15,
			'headers' => [
				'Content-Type' => 'application/json',
				'X-Request-ID' => $request_id,
			],
			'body' => $payload,
		];

		$max_attempts = 3;
		for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
			$response = wp_remote_post($url, $args);

			if (is_wp_error($response)) {
				$result['error'] = 'WP_Error: ' . $response->get_error_message();
				MP_Robokassa_Receipt2_Logger::log('ERROR', $order_id, 'api_attach_wp_error', 'error', [
					'attempt' => $attempt,
					'request_id' => $request_id,
					'message' => $response->get_error_message(),
				]);
				if ($attempt < $max_attempts) {
					self::sleep_backoff($attempt);
					continue;
				}
				return $result;
			}

			$status_code = (int) wp_remote_retrieve_response_code($response);
			$body_raw = (string) wp_remote_retrieve_body($response);
			$body_json = json_decode($body_raw, true);
			$result['status_code'] = $status_code;
			$result['response'] = is_array($body_json) ? $body_json : $body_raw;

			if ($status_code >= 200 && $status_code < 300) {
				$result['ok'] = true;
				$result['receipt_id'] = self::extract_receipt_id($result['response']);
				MP_Robokassa_Receipt2_Logger::log('INFO', $order_id, 'api_attach_success', 'ok', [
					'request_id' => $request_id,
					'status_code' => $status_code,
					'receipt_id' => $result['receipt_id'],
				]);
				return $result;
			}

			// Retry only temporary server errors.
			if ($status_code >= 500 && $status_code <= 599 && $attempt < $max_attempts) {
				MP_Robokassa_Receipt2_Logger::log('ERROR', $order_id, 'api_attach_retry_5xx', 'retry', [
					'attempt' => $attempt,
					'request_id' => $request_id,
					'status_code' => $status_code,
				]);
				self::sleep_backoff($attempt);
				continue;
			}

			$result['error'] = 'HTTP ' . $status_code;
			MP_Robokassa_Receipt2_Logger::log('ERROR', $order_id, 'api_attach_http_error', 'error', [
				'request_id' => $request_id,
				'status_code' => $status_code,
				'attempt' => $attempt,
			]);
			return $result;
		}

		$result['error'] = 'Unknown API failure';
		return $result;
	}

	/**
	 * Read credentials from local plugin settings with fallback to official Robokassa plugin options.
	 *
	 * @return array{login:string,password1:string,country:string}
	 */
	private static function resolve_credentials(): array {
		$login = MP_Robokassa_Receipt2_Settings::get_login();
		$password1 = MP_Robokassa_Receipt2_Settings::get_password1();
		$country = get_option('robokassa_country_code', 'RU');

		// Compatibility adapter: read credentials from official plugin options when local values are empty.
		if ($login === '') {
			$login = trim((string) get_option('robokassa_payment_MerchantLogin', ''));
		}
		if ($password1 === '') {
			$is_test = MP_Robokassa_Receipt2_Settings::is_sandbox() || get_option('robokassa_payment_test_onoff') === 'true';
			$password1 = trim((string) get_option($is_test ? 'robokassa_payment_testshoppass1' : 'robokassa_payment_shoppass1', ''));
		}

		return [
			'login' => $login,
			'password1' => $password1,
			'country' => trim((string) $country) !== '' ? (string) $country : 'RU',
		];
	}

	/**
	 * Build signed body for RoboFiscal/Receipt/Attach.
	 *
	 * @param array<string,mixed> $fields
	 * @param string $password1
	 * @return string
	 */
	private static function build_attach_payload(array $fields, string $password1): string {
		$json = wp_json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if (!is_string($json) || $json === '') {
			return '';
		}

		$startup_hash = self::base64_url_trimmed($json);
		$sign_source = $startup_hash . $password1;
		$sign_md5 = md5($sign_source);
		$sign = self::base64_url_trimmed($sign_md5);

		return $startup_hash . '.' . $sign;
	}

	private static function base64_url_trimmed(string $string): string {
		$base64 = base64_encode($string);
		$replaced = strtr($base64, ['+' => '-', '/' => '_']);
		return preg_replace('/=+$/', '', $replaced) ?: '';
	}

	private static function attach_endpoint(string $country): string {
		// Attach endpoint is shared, kept configurable by country in case of future changes.
		return 'https://ws.roboxchange.com/RoboFiscal/Receipt/Attach';
	}

	private static function generate_request_id(): string {
		if (function_exists('wp_generate_uuid4')) {
			return wp_generate_uuid4();
		}
		return md5(uniqid('mp-rb-receipt2-', true));
	}

	private static function sleep_backoff(int $attempt): void {
		$delay = 1;
		if ($attempt === 2) {
			$delay = 3;
		} elseif ($attempt >= 3) {
			$delay = 8;
		}
		sleep($delay);
	}

	/**
	 * @param mixed $response
	 * @return string
	 */
	private static function extract_receipt_id($response): string {
		if (is_array($response)) {
			foreach (['receipt_id', 'ReceiptId', 'id', 'Id', 'invoice_id', 'InvoiceID'] as $key) {
				if (isset($response[$key]) && is_scalar($response[$key])) {
					return (string) $response[$key];
				}
			}
		}
		return '';
	}
}

