# Changelog

All notable changes to the kwtSMS module will be documented in this file.

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
