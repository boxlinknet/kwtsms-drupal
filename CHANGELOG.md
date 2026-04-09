# Changelog

All notable changes to the kwtSMS module will be documented in this file.

## [1.1.0] - 2026-04-05

### Added
- Log retention policy: auto-delete SMS logs older than configurable days (default 90)
- OTP request event (`kwtsms.otp_request`) for pre-validation by other modules
- SMS send event (`kwtsms.sms_send`) for pre-send hooks by other modules
- Commerce: low stock SMS alerts to admin when product stock drops below threshold
- Commerce: shipping status update notifications to customers
- Commerce: abandoned cart SMS reminders via cron (configurable hours)
- SMS Framework bridge submodule (`kwtsms_smsframework`) for SMS Framework v2 gateway plugin
- New templates: low_stock, shipping_update, abandoned_cart
- Integrations form: toggles for shipping, low stock threshold, and abandoned cart settings
- GPL-2.0-or-later LICENSE.txt file
- Update hook `kwtsms_update_10300` for schema changes

### Fixed
- Coverage filter now correctly uses flat prefix array instead of nested response
- `error_code` column widened from varchar(10) to varchar(32)
- Generic error messages on all OTP/2FA forms to prevent account enumeration
- All phpcs Drupal/DrupalPractice errors and warnings resolved (zero remaining)
- Proper dependency injection throughout (no `\Drupal::` static calls in classes)
- `TimeInterface` namespace corrected (`Component\Datetime`) for Drupal 10.3 compatibility
- `FormBase` property conflicts resolved for `configFactory` and `requestStack`

## [1.0.0] - 2026-03-27

### Added
- kwtSMS gateway integration with login/logout and credential management
- Full send flow with phone normalization, message cleaning, dedup, and balance check
- Bulk sending with 200-number batching and ERR013 backoff
- OTP login (primary mode and two-factor authentication)
- Password reset via SMS (SMS only, Email+SMS, or Email only modes)
- User registration SMS notifications (customer and admin)
- 7-tab admin UI: Dashboard, Settings, Gateway, Templates, Integrations, Logs, Help
- SMS template system with Drupal Token integration
- 4 system default templates (OTP login, password reset, user registered, admin new user)
- Custom kwtSMS tokens: [kwtsms:otp-code], [kwtsms:sender-id], [kwtsms:balance]
- SMS logging with filtering, pagination, and CSV export
- Dashboard with SMS stats and 30-day volume chart
- Daily cron sync for balance, sender IDs, and coverage
- Automatic expired OTP cleanup via cron
- Rate limiting per phone and per IP for OTP requests
- Arabic translations for all module strings
- RTL support in admin CSS
- kwtsms_commerce submodule for Drupal Commerce order notifications
- 4 Commerce templates (order placed, order status, order paid customer, order paid admin)
