## MP Robokassa Receipt2 (Gift Cards)

Отдельный WooCommerce-плагин для отправки второго чека (чек зачета предоплаты) в Robokassa при оплате подарочной картой.

### Что делает плагин

- автоматически отправляет второй чек при `completed` заказа;
- исключает сам товар подарочной карты из позиций второго чека;
- поддерживает default `payment_mode` / `payment_subject`;
- поддерживает правила по категориям с приоритетом;
- добавляет ручную переотправку из карточки заказа;
- ведет лог в `wp-content/uploads/mp-robokassa-receipt2/`.

### Установка

1. Скопируйте папку `mp_robokassa_receipt2` в `wp-content/plugins/`.
2. Активируйте плагин в WordPress.
3. Откройте `WooCommerce -> Robokassa Receipt2`.
4. Заполните:
   - `Login (MerchantLogin)`
   - `Password1`
   - при необходимости `Sandbox/Test`.
5. Включите плагин (`Включить плагин`).

### Настройки признаков чека

- `Default признаки второго чека`:
  - `payment_mode` (рекомендуемо для вашей задачи: `full_payment`);
  - `payment_subject` (рекомендуемо для вашей задачи: `commodity`).
- `Правила по категориям`:
  - укажите категории товаров;
  - задайте приоритет;
  - переопределите `payment_mode` / `payment_subject` для выбранных категорий.

Правила применяются по убыванию `priority`.

### Ручная переотправка

В заказе WooCommerce выберите action:

- `Отправить второй чек Robokassa повторно`

Результат пишется в:

- order notes;
- meta заказа (`mp_rb_receipt2_*`);
- лог-файл плагина.

### Диагностика в админке

В странице `WooCommerce -> Robokassa Receipt2` доступны:

- `Диагностика API`;
- `Preflight-проверка перед выкладкой`;
- `Готовность к загрузке` (PASS/WARN);
- `Инспектор заказа`;
- `Последние строки лога`.

### Ключевые meta поля заказа

- `mp_rb_receipt2_sent`
- `mp_rb_receipt2_id`
- `mp_rb_receipt2_request_id`
- `mp_rb_receipt2_error`
- `mp_rb_receipt2_source_id` (manual override source-id)
- `mp_rb_receipt2_settlement_amount` (manual override суммы зачета)

### Совместимость с официальным Robokassa плагином

Плагин не модифицирует официальный плагин и использует только чтение данных.  
Для поиска `source_id` используются fallback-ключи meta:

- `_transaction_id`
- `transaction_id`
- `robokassa_invoice_id`
- `robokassa_payment_id`
- `robokassa_transaction_id`
- `robokassa_reference`
- `rbk_payment_id`
- `payment_id`

### Безопасность

- admin-действия защищены `nonce`;
- доступ ограничен `manage_woocommerce`;
- чувствительные данные маскируются в логах;
- `Password1` не выводится в открытом виде и не затирается, если поле оставлено пустым.
