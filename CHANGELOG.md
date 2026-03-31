# Changelog

## 0.1.0

- Initial release of `MP Robokassa Receipt2 (Gift Cards)`.
- Automatic second-receipt flow on completed orders.
- Gift-card settlement detection (`pw_gift_card`, meta, fee fallback).
- Category rules for `payment_mode` and `payment_subject`.
- Robokassa API client with retry and request id.
- Manual resend action from WooCommerce order actions.
- Admin page with settings, diagnostics, preflight, readiness, order inspector, and log tail.
- Compatibility fallback with official Robokassa plugin meta keys.
