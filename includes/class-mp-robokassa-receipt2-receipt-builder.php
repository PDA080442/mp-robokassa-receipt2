<?php
if (!defined('ABSPATH')) {
	exit;
}

final class MP_Robokassa_Receipt2_ReceiptBuilder {
	/**
	 * @param WC_Order $order
	 * @param float $settlement_amount
	 * @return array{
	 *   items:array<int,array<string,mixed>>,
	 *   settlements:array<int,array<string,mixed>>,
	 *   total_items_amount:float,
	 *   warnings:array<int,string>
	 * }
	 */
	public static function build(WC_Order $order, float $settlement_amount): array {
		$items = [];
		$warnings = [];
		$total_items_amount = 0.0;

		$default_mode = MP_Robokassa_Receipt2_Settings::get_default_payment_mode();
		$default_subject = MP_Robokassa_Receipt2_Settings::get_default_payment_subject();
		$rules = MP_Robokassa_Receipt2_Settings::get_rules();

		foreach ($order->get_items('line_item') as $item) {
			$product = $item->get_product();
			if (!$product) {
				continue;
			}

			if (self::is_gift_card_product($product)) {
				// Exclude gift card products from second receipt items.
				continue;
			}

			$qty = (float) $item->get_quantity();
			if ($qty <= 0) {
				continue;
			}

			$line_total = (float) $item->get_total();
			if ($line_total <= 0) {
				continue;
			}

			$unit_cost = (float) wc_format_decimal($line_total / $qty, wc_get_price_decimals());
			$qty = (float) wc_format_decimal($qty, 3);
			$sum = (float) wc_format_decimal($unit_cost * $qty, wc_get_price_decimals());
			$total_items_amount += $sum;

			$rule = self::resolve_rule_for_product($product, $rules);
			$payment_mode = $rule['payment_mode'] ?? $default_mode;
			$payment_subject = $rule['payment_subject'] ?? $default_subject;

			$item_payload = [
				'name' => self::sanitize_name($item->get_name()),
				'quantity' => $qty,
				'cost' => self::money($unit_cost),
				'sum' => self::money($sum),
				'payment_method' => $payment_mode,
				'payment_object' => $payment_subject,
				'tax' => self::resolve_tax_code($item),
			];

			$items[] = $item_payload;
		}

		if (empty($items)) {
			$warnings[] = 'No non-gift-card line items included in receipt';
		}

		$total_items_amount = (float) wc_format_decimal($total_items_amount, wc_get_price_decimals());
		$settlement_amount = (float) wc_format_decimal(max(0.0, $settlement_amount), wc_get_price_decimals());
		if ($total_items_amount > 0 && $settlement_amount > $total_items_amount) {
			$warnings[] = 'Settlement amount exceeds items total; clamped';
			$settlement_amount = $total_items_amount;
		}

		$settlements = [[
			'type' => 'prepayment',
			'amount' => self::money($settlement_amount),
		]];

		/**
		 * Post-processing hooks for project-specific customizations.
		 */
		$items = apply_filters('mp_rb_receipt2_items', $items, $order, $settlement_amount);
		$settlements = apply_filters('mp_rb_receipt2_settlements', $settlements, $order, $settlement_amount);

		return [
			'items' => $items,
			'settlements' => $settlements,
			'total_items_amount' => $total_items_amount,
			'warnings' => $warnings,
		];
	}

	/**
	 * @param WC_Order_Item_Product $item
	 * @return string
	 */
	private static function resolve_tax_code(WC_Order_Item_Product $item): string {
		$default_tax = get_option('robokassa_payment_tax') ?: 'none';
		$tax_class = '';
		$product = $item->get_product();
		if ($product) {
			$tax_class = (string) $product->get_tax_class();
		}

		$map = [
			'' => (string) $default_tax,
			'standard' => (string) $default_tax,
			'reduced-rate' => 'vat10',
			'zero-rate' => 'vat0',
		];

		$tax = isset($map[$tax_class]) ? $map[$tax_class] : (string) $default_tax;
		$tax = (string) apply_filters('mp_rb_receipt2_vat_code', $tax, $item, $tax_class);
		return $tax;
	}

	/**
	 * @param string $name
	 * @return string
	 */
	private static function sanitize_name(string $name): string {
		$name = trim(wp_strip_all_tags($name));
		if ($name === '') {
			return 'Product';
		}
		$length = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
		if ($length > 128) {
			return function_exists('mb_substr') ? mb_substr($name, 0, 128) : substr($name, 0, 128);
		}
		return $name;
	}

	/**
	 * @param WC_Product $product
	 * @return bool
	 */
	private static function is_gift_card_product(WC_Product $product): bool {
		$product_to_check = $product->get_parent_id() ? wc_get_product($product->get_parent_id()) : $product;
		if (!$product_to_check) {
			return false;
		}

		if (is_a($product_to_check, 'WC_Product_PW_Gift_Card')) {
			return true;
		}

		$type = method_exists($product_to_check, 'get_type') ? (string) $product_to_check->get_type() : '';
		if (strpos($type, 'gift') !== false) {
			return true;
		}

		$sku = method_exists($product_to_check, 'get_sku') ? (string) $product_to_check->get_sku() : '';
		if ($sku !== '' && stripos($sku, 'gift') !== false) {
			return true;
		}

		return false;
	}

	/**
	 * @param WC_Product $product
	 * @param array<int,array<string,mixed>> $rules
	 * @return array<string,mixed>
	 */
	private static function resolve_rule_for_product(WC_Product $product, array $rules): array {
		if (empty($rules)) {
			return [];
		}

		$product_cat_ids = self::get_product_category_ids($product);
		if (empty($product_cat_ids)) {
			return [];
		}

		foreach ($rules as $rule) {
			if (empty($rule['enabled'])) {
				continue;
			}
			$rule_cats = isset($rule['category_ids']) && is_array($rule['category_ids']) ? array_map('intval', $rule['category_ids']) : [];
			if (empty($rule_cats)) {
				continue;
			}
			$intersect = array_intersect($product_cat_ids, $rule_cats);
			if (!empty($intersect)) {
				return $rule;
			}
		}

		return [];
	}

	/**
	 * @param WC_Product $product
	 * @return array<int,int>
	 */
	private static function get_product_category_ids(WC_Product $product): array {
		$ids = [];
		if (method_exists($product, 'get_category_ids')) {
			$ids = array_map('intval', (array) $product->get_category_ids());
		}
		if (empty($ids) && $product->get_parent_id() > 0) {
			$parent = wc_get_product($product->get_parent_id());
			if ($parent && method_exists($parent, 'get_category_ids')) {
				$ids = array_map('intval', (array) $parent->get_category_ids());
			}
		}
		return array_values(array_unique(array_filter($ids, static function ($id) {
			return (int) $id > 0;
		})));
	}

	private static function money(float $amount): string {
		return number_format((float) wc_format_decimal($amount, wc_get_price_decimals()), 2, '.', '');
	}
}

