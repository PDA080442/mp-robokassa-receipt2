<?php
if (!defined('ABSPATH')) {
	exit;
}

final class MP_Robokassa_Receipt2_OrderLinks {
	private const META_SOURCE_ID = 'mp_rb_receipt2_source_id';
	private const META_SETTLEMENT_AMOUNT = 'mp_rb_receipt2_settlement_amount';

	/**
	 * @param WC_Order $order
	 * @return array{
	 *   is_gift_card_settlement:bool,
	 *   settlement_amount:float,
	 *   source_id:string,
	 *   reason:string,
	 *   card_numbers:array<int,string>
	 * }
	 */
	public static function resolve_for_order(WC_Order $order): array {
		$result = [
			'is_gift_card_settlement' => false,
			'settlement_amount' => 0.0,
			'source_id' => '',
			'reason' => '',
			'card_numbers' => [],
		];

		// Full override hook.
		$override = apply_filters('mp_rb_receipt2_order_links', null, $order);
		if (is_array($override)) {
			$result['is_gift_card_settlement'] = !empty($override['is_gift_card_settlement']);
			$result['settlement_amount'] = isset($override['settlement_amount']) ? max(0.0, (float) $override['settlement_amount']) : 0.0;
			$result['source_id'] = isset($override['source_id']) ? trim((string) $override['source_id']) : '';
			$result['reason'] = 'resolved_by_filter';
			$result['card_numbers'] = !empty($override['card_numbers']) && is_array($override['card_numbers']) ? array_values(array_unique(array_map('strval', $override['card_numbers']))) : [];
			return $result;
		}

		$order_id = (int) $order->get_id();

		// 1) Preferred source: PW Gift Card lines.
		$pwgc = self::detect_from_pwgc_lines($order);
		if ($pwgc['settlement_amount'] > 0) {
			$result['is_gift_card_settlement'] = true;
			$result['settlement_amount'] = $pwgc['settlement_amount'];
			$result['card_numbers'] = $pwgc['card_numbers'];
			$result['reason'] = 'detected_from_pw_gift_card_lines';
		}

		// 2) Explicit meta fallback.
		$meta_settlement = (float) get_post_meta($order_id, self::META_SETTLEMENT_AMOUNT, true);
		if (!$result['is_gift_card_settlement'] && $meta_settlement > 0) {
			$result['is_gift_card_settlement'] = true;
			$result['settlement_amount'] = (float) wc_format_decimal($meta_settlement, wc_get_price_decimals());
			$result['reason'] = 'detected_from_meta';
		}

		// 3) Fee fallback (negative gift-card fee line).
		if (!$result['is_gift_card_settlement']) {
			$fee_amount = self::detect_from_fees($order);
			if ($fee_amount > 0) {
				$result['is_gift_card_settlement'] = true;
				$result['settlement_amount'] = $fee_amount;
				$result['reason'] = 'detected_from_fees';
			}
		}

		// Resolve source_id:
		$source_id = trim((string) get_post_meta($order_id, self::META_SOURCE_ID, true));
		if ($source_id !== '') {
			$result['source_id'] = $source_id;
			$result['reason'] = $result['reason'] !== '' ? $result['reason'] : 'source_from_meta';
		}

		// Fallback by known official-plugin/woocommerce meta keys on current order.
		if ($result['source_id'] === '') {
			$source_id = self::resolve_source_id_from_order_meta($order_id);
			if ($source_id !== '') {
				$result['source_id'] = $source_id;
				$result['reason'] = 'source_resolved_from_order_meta_keys';
			}
		}

		// Fallback by transaction id on current order.
		if ($result['source_id'] === '') {
			$source_id = trim((string) $order->get_transaction_id());
			if ($source_id !== '') {
				$result['source_id'] = $source_id;
				$result['reason'] = 'source_resolved_from_current_order_transaction_id';
			}
		}

		// Try by card number -> issuance order -> transaction id.
		if ($result['source_id'] === '' && !empty($result['card_numbers'])) {
			$by_cards = self::resolve_source_ids_by_card_numbers($result['card_numbers']);
			if ($by_cards['ok']) {
				$result['source_id'] = $by_cards['source_id'];
				$result['reason'] = 'source_resolved_by_card_number';
			} else {
				$result['reason'] = $by_cards['reason'];
			}
		}

		// Last chance hook for source_id only.
		if ($result['source_id'] === '') {
			$source_id = apply_filters('mp_rb_receipt2_source_id', '', $order, $result);
			$result['source_id'] = trim((string) $source_id);
			if ($result['source_id'] !== '') {
				$result['reason'] = 'source_resolved_by_filter';
			}
		}

		if ($result['reason'] === '') {
			$result['reason'] = $result['is_gift_card_settlement'] ? 'detected' : 'not_detected';
		}

		return $result;
	}

	/**
	 * @param WC_Order $order
	 * @return array{settlement_amount:float,card_numbers:array<int,string>}
	 */
	private static function detect_from_pwgc_lines(WC_Order $order): array {
		$amount = 0.0;
		$card_numbers = [];

		foreach ($order->get_items('pw_gift_card') as $line) {
			$line_amount = method_exists($line, 'get_amount') ? (float) $line->get_amount() : 0.0;
			if ($line_amount > 0) {
				$amount += $line_amount;
			}

			$card = method_exists($line, 'get_card_number') ? trim((string) $line->get_card_number()) : '';
			if ($card !== '') {
				$card_numbers[] = $card;
			}
		}

		return [
			'settlement_amount' => (float) wc_format_decimal($amount, wc_get_price_decimals()),
			'card_numbers' => array_values(array_unique($card_numbers)),
		];
	}

	/**
	 * @param WC_Order $order
	 * @return float
	 */
	private static function detect_from_fees(WC_Order $order): float {
		$total = 0.0;
		foreach ($order->get_items('fee') as $fee) {
			$name_raw = (string) $fee->get_name();
			$name = function_exists('mb_strtolower') ? mb_strtolower($name_raw) : strtolower($name_raw);
			$fee_total = (float) $fee->get_total();
			$is_gc_fee = strpos($name, 'gift card') !== false
				|| strpos($name, 'pwgc') !== false
				|| strpos($name, 'подароч') !== false;
			if ($is_gc_fee && $fee_total < 0) {
				$total += abs($fee_total);
			}
		}
		return (float) wc_format_decimal($total, wc_get_price_decimals());
	}

	/**
	 * Resolve source ids from card numbers through custom card table/order linkage.
	 *
	 * @param array<int,string> $card_numbers
	 * @return array{ok:bool,source_id:string,reason:string}
	 */
	private static function resolve_source_ids_by_card_numbers(array $card_numbers): array {
		global $wpdb;
		if (empty($card_numbers) || !isset($wpdb)) {
			return ['ok' => false, 'source_id' => '', 'reason' => 'no_card_numbers_for_source_lookup'];
		}

		$table_name = function_exists('wgpc_get_table_name')
			? (string) wgpc_get_table_name()
			: $wpdb->prefix . 'mpgc_physical_cards';

		$source_ids = [];
		foreach ($card_numbers as $card_number) {
			$issuance_order_id = (int) $wpdb->get_var(
				$wpdb->prepare("SELECT order_id FROM {$table_name} WHERE card_number = %s LIMIT 1", $card_number)
			);
			if ($issuance_order_id <= 0) {
				continue;
			}

			$issuance_order = wc_get_order($issuance_order_id);
			if (!$issuance_order) {
				continue;
			}

			$candidate = trim((string) $issuance_order->get_transaction_id());
			if ($candidate === '') {
				$candidate = self::resolve_source_id_from_order_meta($issuance_order_id);
			}
			if ($candidate !== '') {
				$source_ids[] = $candidate;
			}
		}

		$source_ids = array_values(array_unique($source_ids));
		if (count($source_ids) === 1) {
			return ['ok' => true, 'source_id' => $source_ids[0], 'reason' => ''];
		}
		if (count($source_ids) > 1) {
			// Ambiguous case: do not pick one automatically.
			return ['ok' => false, 'source_id' => '', 'reason' => 'ambiguous_multiple_source_ids'];
		}
		return ['ok' => false, 'source_id' => '', 'reason' => 'source_id_not_found_by_card_number'];
	}

	/**
	 * Resolve transaction/reference from known meta key list.
	 *
	 * @param int $order_id
	 * @return string
	 */
	private static function resolve_source_id_from_order_meta(int $order_id): string {
		$keys = MP_Robokassa_Receipt2_Settings::get_source_meta_keys();
		foreach ($keys as $key) {
			$value = trim((string) get_post_meta($order_id, (string) $key, true));
			if ($value !== '') {
				return $value;
			}
		}
		return '';
	}
}

