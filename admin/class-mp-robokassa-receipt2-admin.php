<?php
if (!defined('ABSPATH')) {
	exit;
}

final class MP_Robokassa_Receipt2_Admin {
	private const PAGE_SLUG = 'mp-robokassa-receipt2';
	private const NONCE_ACTION_API_CHECK = 'mp_rb_receipt2_api_check';

	public static function init(): void {
		add_action('admin_menu', [self::class, 'register_menu']);
		add_action('admin_init', [self::class, 'register_settings']);
	}

	public static function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			'Robokassa Receipt2',
			'Robokassa Receipt2',
			'manage_woocommerce',
			self::PAGE_SLUG,
			[self::class, 'render_page']
		);
	}

	public static function register_settings(): void {
		register_setting('mp_rb_receipt2', MP_Robokassa_Receipt2_Settings::OPTION_ENABLED, [
			'type' => 'boolean',
			'sanitize_callback' => [self::class, 'sanitize_checkbox'],
			'default' => false,
		]);
		register_setting('mp_rb_receipt2', MP_Robokassa_Receipt2_Settings::OPTION_SANDBOX, [
			'type' => 'boolean',
			'sanitize_callback' => [self::class, 'sanitize_checkbox'],
			'default' => false,
		]);
		register_setting('mp_rb_receipt2', MP_Robokassa_Receipt2_Settings::OPTION_LOGIN, [
			'type' => 'string',
			'sanitize_callback' => [self::class, 'sanitize_string'],
			'default' => '',
		]);
		register_setting('mp_rb_receipt2', MP_Robokassa_Receipt2_Settings::OPTION_PASSWORD1, [
			'type' => 'string',
			'sanitize_callback' => [self::class, 'sanitize_string'],
			'default' => '',
		]);
		register_setting('mp_rb_receipt2', MP_Robokassa_Receipt2_Settings::OPTION_DEBUG, [
			'type' => 'boolean',
			'sanitize_callback' => [self::class, 'sanitize_checkbox'],
			'default' => false,
		]);
		register_setting('mp_rb_receipt2', MP_Robokassa_Receipt2_Settings::OPTION_DEFAULT_PAYMENT_MODE, [
			'type' => 'string',
			'sanitize_callback' => [self::class, 'sanitize_payment_mode'],
			'default' => 'full_payment',
		]);
		register_setting('mp_rb_receipt2', MP_Robokassa_Receipt2_Settings::OPTION_DEFAULT_PAYMENT_SUBJECT, [
			'type' => 'string',
			'sanitize_callback' => [self::class, 'sanitize_payment_subject'],
			'default' => 'commodity',
		]);
		register_setting('mp_rb_receipt2', MP_Robokassa_Receipt2_Settings::OPTION_RULES, [
			'type' => 'array',
			'sanitize_callback' => [self::class, 'sanitize_rules'],
			'default' => [],
		]);
	}

	public static function sanitize_checkbox($value): bool {
		return (bool) $value;
	}

	public static function sanitize_string($value): string {
		return trim((string) $value);
	}

	public static function sanitize_payment_mode($value): string {
		$value = trim((string) $value);
		if (!in_array($value, MP_Robokassa_Receipt2_Settings::allowed_payment_modes(), true)) {
			return 'full_payment';
		}
		return $value;
	}

	public static function sanitize_payment_subject($value): string {
		$value = trim((string) $value);
		if (!in_array($value, MP_Robokassa_Receipt2_Settings::allowed_payment_subjects(), true)) {
			return 'commodity';
		}
		return $value;
	}

	/**
	 * @param mixed $rules
	 * @return array<int,array<string,mixed>>
	 */
	public static function sanitize_rules($rules): array {
		if (!is_array($rules)) {
			return [];
		}

		$allowed_modes = MP_Robokassa_Receipt2_Settings::allowed_payment_modes();
		$allowed_subjects = MP_Robokassa_Receipt2_Settings::allowed_payment_subjects();
		$valid_category_ids = self::get_valid_product_category_ids();
		$normalized = [];
		$skipped = 0;

		foreach ($rules as $rule) {
			if (!is_array($rule)) {
				$skipped++;
				continue;
			}

			$enabled = !empty($rule['enabled']);
			$priority = isset($rule['priority']) ? (int) $rule['priority'] : 100;
			$payment_mode = isset($rule['payment_mode']) ? trim((string) $rule['payment_mode']) : '';
			$payment_subject = isset($rule['payment_subject']) ? trim((string) $rule['payment_subject']) : '';
			if (!in_array($payment_mode, $allowed_modes, true) || !in_array($payment_subject, $allowed_subjects, true)) {
				$skipped++;
				continue;
			}

			$category_ids = [];
			if (!empty($rule['category_ids']) && is_array($rule['category_ids'])) {
				foreach ($rule['category_ids'] as $cat_id) {
					$cat_id = (int) $cat_id;
					if ($cat_id > 0 && isset($valid_category_ids[$cat_id])) {
						$category_ids[] = $cat_id;
					}
				}
			}
			$category_ids = array_values(array_unique($category_ids));
			if (empty($category_ids)) {
				$skipped++;
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

		if ($skipped > 0) {
			add_settings_error(
				'mp_rb_receipt2',
				'mp_rb_receipt2_rules_skipped',
				sprintf('Некоторые правила (%d) не сохранены: невалидные значения или категории.', $skipped),
				'warning'
			);
		}

		return $normalized;
	}

	/**
	 * @return array<int,bool>
	 */
	private static function get_valid_product_category_ids(): array {
		$result = [];
		$terms = get_terms([
			'taxonomy' => 'product_cat',
			'hide_empty' => false,
			'fields' => 'ids',
		]);
		if (is_wp_error($terms) || !is_array($terms)) {
			return $result;
		}
		foreach ($terms as $term_id) {
			$term_id = (int) $term_id;
			if ($term_id > 0) {
				$result[$term_id] = true;
			}
		}
		return $result;
	}

	public static function render_page(): void {
		if (!current_user_can('manage_woocommerce')) {
			wp_die('Access denied');
		}

		$api_check_result = null;
		if (isset($_POST['mp_rb_receipt2_api_check'])) {
			check_admin_referer(self::NONCE_ACTION_API_CHECK);
			$api_check_result = self::run_api_diagnostic();
		}

		$inspect_order_id = isset($_GET['mp_rb_receipt2_inspect_order']) ? (int) $_GET['mp_rb_receipt2_inspect_order'] : 0;
		$inspect_result = $inspect_order_id > 0 ? self::inspect_order($inspect_order_id) : null;

		$enabled = MP_Robokassa_Receipt2_Settings::is_enabled();
		$sandbox = MP_Robokassa_Receipt2_Settings::is_sandbox();
		$login = MP_Robokassa_Receipt2_Settings::get_login();
		$password1 = MP_Robokassa_Receipt2_Settings::get_password1();
		$debug = MP_Robokassa_Receipt2_Settings::is_debug();
		$default_mode = MP_Robokassa_Receipt2_Settings::get_default_payment_mode();
		$default_subject = MP_Robokassa_Receipt2_Settings::get_default_payment_subject();
		$rules = MP_Robokassa_Receipt2_Settings::get_rules();
		$errors = MP_Robokassa_Receipt2_Settings::validate_for_api();
		$preflight = self::build_preflight_checks($rules, $errors);
		$readiness_ok = empty($preflight['warnings']);
		$log_tail = self::read_log_tail(40);

		$categories = get_terms([
			'taxonomy' => 'product_cat',
			'hide_empty' => false,
		]);
		if (!is_array($categories)) {
			$categories = [];
		}
		?>
		<div class="wrap">
			<h1>Robokassa Receipt2</h1>
			<p>Настройки второго чека Robokassa (подарочные карты).</p>

			<?php settings_errors('mp_rb_receipt2'); ?>
			<?php if (!empty($errors)) : ?>
				<div style="background:#fff4e5;border-left:4px solid #dba617;padding:10px;max-width:1300px;">
					<strong>Конфигурация содержит ошибки:</strong>
					<ul style="margin:8px 0 0 18px;">
						<?php foreach ($errors as $err) : ?>
							<li><?php echo esc_html($err); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<div style="margin-top:12px;background:#fff;border:1px solid #ccd0d4;padding:16px;max-width:1300px;">
				<h2>Диагностика API</h2>
				<p>Проверка доступности RoboFiscal endpoint и сетевого соединения сервера.</p>
				<form method="post">
					<?php wp_nonce_field(self::NONCE_ACTION_API_CHECK); ?>
					<input type="hidden" name="mp_rb_receipt2_api_check" value="1">
					<?php submit_button('Проверить API', 'secondary', 'submit', false); ?>
				</form>
				<?php if (is_array($api_check_result)) : ?>
					<div style="margin-top:10px;padding:10px;border-left:4px solid <?php echo !empty($api_check_result['ok']) ? '#46b450' : '#dc3232'; ?>;background:#f8f8f8;">
						<strong><?php echo !empty($api_check_result['ok']) ? 'API доступен' : 'API недоступен'; ?></strong>
						<div>HTTP: <?php echo esc_html((string) $api_check_result['status_code']); ?></div>
						<div><?php echo esc_html((string) $api_check_result['message']); ?></div>
					</div>
				<?php endif; ?>
			</div>

			<div style="margin-top:12px;background:#fff;border:1px solid #ccd0d4;padding:16px;max-width:1300px;">
				<h2>Preflight-проверка перед выкладкой</h2>
				<?php if (!empty($preflight['warnings'])) : ?>
					<ul style="margin:0 0 0 18px;">
						<?php foreach ($preflight['warnings'] as $warning) : ?>
							<li style="color:#b32d2e;"><?php echo esc_html($warning); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<p style="color:#22863a;margin:0;">Критичных предупреждений нет.</p>
				<?php endif; ?>
			</div>

			<div style="margin-top:12px;background:#fff;border:1px solid #ccd0d4;padding:16px;max-width:1300px;">
				<h2>Готовность к загрузке</h2>
				<p style="margin:0;">
					<strong>Статус: </strong>
					<span style="color:<?php echo $readiness_ok ? '#22863a' : '#b32d2e'; ?>;">
						<?php echo $readiness_ok ? 'PASS' : 'WARN'; ?>
					</span>
				</p>
				<ul style="margin:8px 0 0 18px;">
					<?php foreach ($preflight['checks'] as $check) : ?>
						<li>
							<span style="color:<?php echo !empty($check['ok']) ? '#22863a' : '#b32d2e'; ?>;">
								<?php echo !empty($check['ok']) ? 'PASS' : 'WARN'; ?>
							</span>
							- <?php echo esc_html((string) $check['label']); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields('mp_rb_receipt2'); ?>

				<div style="background:#fff;border:1px solid #ccd0d4;padding:16px;max-width:1300px;">
					<h2>Основные настройки</h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">Включить плагин</th>
							<td><label><input type="checkbox" name="<?php echo esc_attr(MP_Robokassa_Receipt2_Settings::OPTION_ENABLED); ?>" value="1" <?php checked($enabled); ?>> Да</label></td>
						</tr>
						<tr>
							<th scope="row">Sandbox/Test</th>
							<td><label><input type="checkbox" name="<?php echo esc_attr(MP_Robokassa_Receipt2_Settings::OPTION_SANDBOX); ?>" value="1" <?php checked($sandbox); ?>> Использовать тестовый режим</label></td>
						</tr>
						<tr>
							<th scope="row">Login (MerchantLogin)</th>
							<td><input class="regular-text" type="text" name="<?php echo esc_attr(MP_Robokassa_Receipt2_Settings::OPTION_LOGIN); ?>" value="<?php echo esc_attr($login); ?>"></td>
						</tr>
						<tr>
							<th scope="row">Password1</th>
							<td><input class="regular-text" type="password" name="<?php echo esc_attr(MP_Robokassa_Receipt2_Settings::OPTION_PASSWORD1); ?>" value="<?php echo esc_attr($password1); ?>"></td>
						</tr>
						<tr>
							<th scope="row">Debug</th>
							<td><label><input type="checkbox" name="<?php echo esc_attr(MP_Robokassa_Receipt2_Settings::OPTION_DEBUG); ?>" value="1" <?php checked($debug); ?>> Включить debug лог</label></td>
						</tr>
					</table>

					<h2>Default признаки второго чека</h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">payment_mode</th>
							<td>
								<select name="<?php echo esc_attr(MP_Robokassa_Receipt2_Settings::OPTION_DEFAULT_PAYMENT_MODE); ?>">
									<?php foreach (MP_Robokassa_Receipt2_Settings::allowed_payment_modes() as $mode) : ?>
										<option value="<?php echo esc_attr($mode); ?>" <?php selected($default_mode, $mode); ?>><?php echo esc_html($mode); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row">payment_subject</th>
							<td>
								<select name="<?php echo esc_attr(MP_Robokassa_Receipt2_Settings::OPTION_DEFAULT_PAYMENT_SUBJECT); ?>">
									<?php foreach (MP_Robokassa_Receipt2_Settings::allowed_payment_subjects() as $subject) : ?>
										<option value="<?php echo esc_attr($subject); ?>" <?php selected($default_subject, $subject); ?>><?php echo esc_html($subject); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					</table>

					<h2>Правила по категориям</h2>
					<p>Правила применяются по убыванию `priority`.</p>
					<table class="widefat striped" id="mp-rb-receipt2-rules-table">
						<thead>
						<tr>
							<th style="width:90px;">Вкл</th>
							<th style="width:120px;">Priority</th>
							<th>Категории</th>
							<th style="width:220px;">payment_mode</th>
							<th style="width:220px;">payment_subject</th>
							<th style="width:80px;">Удалить</th>
						</tr>
						</thead>
						<tbody>
						<?php foreach ($rules as $idx => $rule) : ?>
							<tr>
								<td>
									<input type="hidden" name="<?php echo esc_attr(MP_Robokassa_Receipt2_Settings::OPTION_RULES . '[' . $idx . '][enabled]'); ?>" value="0">
									<label><input type="checkbox" name="<?php echo esc_attr(MP_Robokassa_Receipt2_Settings::OPTION_RULES . '[' . $idx . '][enabled]'); ?>" value="1" <?php checked(!empty($rule['enabled'])); ?>> Да</label>
								</td>
								<td><input type="number" style="width:100px;" name="<?php echo esc_attr(MP_Robokassa_Receipt2_Settings::OPTION_RULES . '[' . $idx . '][priority]'); ?>" value="<?php echo esc_attr((string) (int) $rule['priority']); ?>"></td>
								<td>
									<select multiple size="5" style="min-width:280px;" name="<?php echo esc_attr(MP_Robokassa_Receipt2_Settings::OPTION_RULES . '[' . $idx . '][category_ids][]'); ?>">
										<?php foreach ($categories as $cat) : ?>
											<option value="<?php echo esc_attr((string) $cat->term_id); ?>" <?php selected(in_array((int) $cat->term_id, (array) $rule['category_ids'], true)); ?>>
												<?php echo esc_html($cat->name . ' (#' . $cat->term_id . ')'); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>
								<td>
									<select name="<?php echo esc_attr(MP_Robokassa_Receipt2_Settings::OPTION_RULES . '[' . $idx . '][payment_mode]'); ?>">
										<?php foreach (MP_Robokassa_Receipt2_Settings::allowed_payment_modes() as $mode) : ?>
											<option value="<?php echo esc_attr($mode); ?>" <?php selected($rule['payment_mode'], $mode); ?>><?php echo esc_html($mode); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
								<td>
									<select name="<?php echo esc_attr(MP_Robokassa_Receipt2_Settings::OPTION_RULES . '[' . $idx . '][payment_subject]'); ?>">
										<?php foreach (MP_Robokassa_Receipt2_Settings::allowed_payment_subjects() as $subject) : ?>
											<option value="<?php echo esc_attr($subject); ?>" <?php selected($rule['payment_subject'], $subject); ?>><?php echo esc_html($subject); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
								<td><button type="button" class="button mp-rb-receipt2-remove-rule">Удалить</button></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>

					<p style="margin-top:10px;">
						<button type="button" class="button" id="mp-rb-receipt2-add-rule">Добавить правило</button>
					</p>

					<?php submit_button('Сохранить настройки'); ?>
				</div>
			</form>

			<div style="margin-top:12px;background:#fff;border:1px solid #ccd0d4;padding:16px;max-width:1300px;">
				<h2>Инспектор заказа</h2>
				<form method="get">
					<input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">
					<label for="mp-rb-receipt2-inspect-order">Order ID: </label>
					<input id="mp-rb-receipt2-inspect-order" type="number" name="mp_rb_receipt2_inspect_order" value="<?php echo $inspect_order_id > 0 ? esc_attr((string) $inspect_order_id) : ''; ?>" min="1">
					<?php submit_button('Проверить заказ', 'secondary', 'submit', false); ?>
				</form>

				<?php if (is_array($inspect_result)) : ?>
					<?php if (empty($inspect_result['order_found'])) : ?>
						<p style="color:#b32d2e;">Заказ не найден.</p>
					<?php else : ?>
						<p><strong>Order #<?php echo esc_html((string) $inspect_result['order_id']); ?></strong></p>
						<p><strong>Resolve:</strong> <?php echo esc_html(wp_json_encode($inspect_result['resolved'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></p>
						<p><strong>Build:</strong> <?php echo esc_html(wp_json_encode($inspect_result['built'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></p>
						<p><strong>Meta:</strong> <?php echo esc_html(wp_json_encode($inspect_result['meta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></p>
					<?php endif; ?>
				<?php endif; ?>
			</div>

			<div style="margin-top:12px;background:#fff;border:1px solid #ccd0d4;padding:16px;max-width:1300px;">
				<h2>Последние строки лога</h2>
				<pre style="max-height:280px;overflow:auto;background:#111;color:#ddd;padding:10px;"><?php echo esc_html($log_tail); ?></pre>
			</div>
		</div>

		<script>
		(function() {
			const table = document.getElementById('mp-rb-receipt2-rules-table');
			const addBtn = document.getElementById('mp-rb-receipt2-add-rule');
			if (!table || !addBtn) return;
			const tbody = table.querySelector('tbody');
			const categoriesHtml = <?php echo wp_json_encode(implode('', array_map(static function($cat) {
				return '<option value="' . (int) $cat->term_id . '">' . esc_html($cat->name . ' (#' . $cat->term_id . ')') . '</option>';
			}, $categories))); ?>;
			const modes = <?php echo wp_json_encode(MP_Robokassa_Receipt2_Settings::allowed_payment_modes()); ?>;
			const subjects = <?php echo wp_json_encode(MP_Robokassa_Receipt2_Settings::allowed_payment_subjects()); ?>;
			const optionName = <?php echo wp_json_encode(MP_Robokassa_Receipt2_Settings::OPTION_RULES); ?>;

			function buildOptions(items, selected) {
				return items.map((v) => '<option value="' + v + '"' + (v === selected ? ' selected' : '') + '>' + v + '</option>').join('');
			}

			function rowHtml(idx) {
				return '' +
				'<tr>' +
					'<td><input type="hidden" name="' + optionName + '[' + idx + '][enabled]" value="0"><label><input type="checkbox" name="' + optionName + '[' + idx + '][enabled]" value="1" checked> Да</label></td>' +
					'<td><input type="number" name="' + optionName + '[' + idx + '][priority]" value="100" style="width:100px;"></td>' +
					'<td><select multiple size="5" name="' + optionName + '[' + idx + '][category_ids][]" style="min-width:280px;">' + categoriesHtml + '</select></td>' +
					'<td><select name="' + optionName + '[' + idx + '][payment_mode]">' + buildOptions(modes, 'full_payment') + '</select></td>' +
					'<td><select name="' + optionName + '[' + idx + '][payment_subject]">' + buildOptions(subjects, 'commodity') + '</select></td>' +
					'<td><button type="button" class="button mp-rb-receipt2-remove-rule">Удалить</button></td>' +
				'</tr>';
			}

			addBtn.addEventListener('click', function() {
				const idx = tbody.querySelectorAll('tr').length;
				tbody.insertAdjacentHTML('beforeend', rowHtml(idx));
			});

			tbody.addEventListener('click', function(e) {
				if (e.target && e.target.classList.contains('mp-rb-receipt2-remove-rule')) {
					const tr = e.target.closest('tr');
					if (tr) tr.remove();
				}
			});
		})();
		</script>
		<?php
	}

	/**
	 * @return array{ok:bool,status_code:int,message:string}
	 */
	private static function run_api_diagnostic(): array {
		$url = 'https://ws.roboxchange.com/RoboFiscal/Receipt/Attach';
		$response = wp_remote_post($url, [
			'timeout' => 10,
			'headers' => ['Content-Type' => 'application/json'],
			'body' => '{}',
		]);
		if (is_wp_error($response)) {
			return [
				'ok' => false,
				'status_code' => 0,
				'message' => 'WP_Error: ' . $response->get_error_message(),
			];
		}
		$status_code = (int) wp_remote_retrieve_response_code($response);
		$ok = $status_code > 0;
		$msg = $ok ? 'Endpoint reachable (even if request body is invalid).' : 'Unexpected empty HTTP status';
		return [
			'ok' => $ok,
			'status_code' => $status_code,
			'message' => $msg,
		];
	}

	/**
	 * @param array<int,array<string,mixed>> $rules
	 * @param array<int,string> $errors
	 * @return array{
	 *   checks:array<int,array{label:string,ok:bool}>,
	 *   warnings:array<int,string>
	 * }
	 */
	private static function build_preflight_checks(array $rules, array $errors): array {
		$checks = [];
		$warnings = [];

		$checks[] = [
			'label' => 'Плагин включен',
			'ok' => MP_Robokassa_Receipt2_Settings::is_enabled(),
		];
		$checks[] = [
			'label' => 'Заполнены credentials (login/password1)',
			'ok' => MP_Robokassa_Receipt2_Settings::get_login() !== '' && MP_Robokassa_Receipt2_Settings::get_password1() !== '',
		];
		$checks[] = [
			'label' => 'Есть минимум одно активное правило по категориям',
			'ok' => self::has_enabled_rules($rules),
		];
		$checks[] = [
			'label' => 'Доступна запись в uploads для логов',
			'ok' => self::is_log_dir_writable(),
		];
		$checks[] = [
			'label' => 'Нет базовых ошибок validate_for_api',
			'ok' => empty($errors),
		];

		foreach ($checks as $check) {
			if (empty($check['ok'])) {
				$warnings[] = (string) $check['label'];
			}
		}
		return [
			'checks' => $checks,
			'warnings' => $warnings,
		];
	}

	/**
	 * @param int $order_id
	 * @return array<string,mixed>
	 */
	private static function inspect_order(int $order_id): array {
		$order = wc_get_order($order_id);
		if (!$order instanceof WC_Order) {
			return [
				'order_found' => false,
				'order_id' => $order_id,
			];
		}

		$resolved = MP_Robokassa_Receipt2_OrderLinks::resolve_for_order($order);
		$built = MP_Robokassa_Receipt2_ReceiptBuilder::build($order, (float) $resolved['settlement_amount']);

		return [
			'order_found' => true,
			'order_id' => $order_id,
			'resolved' => $resolved,
			'built' => [
				'items_count' => is_array($built['items']) ? count($built['items']) : 0,
				'settlements' => $built['settlements'],
				'total_items_amount' => $built['total_items_amount'],
				'warnings' => $built['warnings'],
			],
			'meta' => [
				'sent' => get_post_meta($order_id, 'mp_rb_receipt2_sent', true),
				'receipt_id' => get_post_meta($order_id, 'mp_rb_receipt2_id', true),
				'request_id' => get_post_meta($order_id, 'mp_rb_receipt2_request_id', true),
				'error' => get_post_meta($order_id, 'mp_rb_receipt2_error', true),
				'source_id' => get_post_meta($order_id, 'mp_rb_receipt2_source_id', true),
				'settlement_amount' => get_post_meta($order_id, 'mp_rb_receipt2_settlement_amount', true),
			],
		];
	}

	private static function has_enabled_rules(array $rules): bool {
		foreach ($rules as $rule) {
			if (!empty($rule['enabled'])) {
				return true;
			}
		}
		return false;
	}

	private static function is_log_dir_writable(): bool {
		$uploads = wp_upload_dir();
		$base = is_array($uploads) && !empty($uploads['basedir']) ? $uploads['basedir'] : WP_CONTENT_DIR . '/uploads';
		$dir = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'mp-robokassa-receipt2';
		if (!is_dir($dir)) {
			if (function_exists('wp_mkdir_p')) {
				wp_mkdir_p($dir);
			}
		}
		return is_dir($dir) && is_writable($dir);
	}

	private static function read_log_tail(int $lines = 40): string {
		$uploads = wp_upload_dir();
		$base = is_array($uploads) && !empty($uploads['basedir']) ? $uploads['basedir'] : WP_CONTENT_DIR . '/uploads';
		$dir = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'mp-robokassa-receipt2';
		$path = $dir . DIRECTORY_SEPARATOR . 'receipt2-' . date('Y-m') . '.log';
		if (!is_file($path) || !is_readable($path)) {
			return 'Лог-файл не найден или недоступен.';
		}
		$content = (string) @file_get_contents($path);
		if ($content === '') {
			return 'Лог пуст.';
		}
		$all_lines = preg_split("/\r\n|\n|\r/", trim($content));
		if (!is_array($all_lines) || empty($all_lines)) {
			return 'Лог пуст.';
		}
		$tail = array_slice($all_lines, -1 * max(1, $lines));
		return implode("\n", $tail);
	}
}

