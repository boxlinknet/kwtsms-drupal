# kwtSMS for Drupal

[![CI](https://github.com/boxlinknet/kwtsms-drupal/actions/workflows/ci.yml/badge.svg)](https://github.com/boxlinknet/kwtsms-drupal/actions/workflows/ci.yml)
[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)
[![Drupal 10.3+](https://img.shields.io/badge/Drupal-10.3%2B%20%7C%2011-0678be.svg)](https://www.drupal.org)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777BB4.svg)](https://www.php.net)
[![kwtSMS](https://img.shields.io/badge/Gateway-kwtSMS.com-FFA200.svg)](https://www.kwtsms.com)

SMS gateway integration with [kwtSMS.com](https://www.kwtsms.com) for Drupal 10.3+ and 11. OTP login, two-factor authentication, password reset via SMS, user registration notifications, and Commerce order alerts.

## About kwtSMS

[kwtSMS](https://www.kwtsms.com) is a Kuwait-based SMS gateway providing reliable A2P (Application-to-Person) messaging services. It supports SMS delivery to Kuwait and 200+ countries, with features including sender ID registration, delivery reports for international messages, and a REST/JSON API.

- **Website**: [kwtsms.com](https://www.kwtsms.com)
- **API Documentation**: [kwtsms.com/developers.html](https://www.kwtsms.com/developers.html)
- **All Integrations**: [kwtsms.com/integrations.html](https://www.kwtsms.com/integrations.html)

## Features

**Authentication**
- OTP login: use SMS as the primary login method or as a second factor (2FA), configurable per role
- Password reset via SMS: configurable as SMS only, Email + SMS, or Email only (default)
- Rate limiting per phone number and per IP for OTP requests
- Lockout after failed verification attempts

**Notifications**
- User registration SMS to customers and admins
- Admin alerts for new user registrations
- Commerce order notifications: order placed, status updates, payment confirmations (via submodule)
- Commerce low stock alerts to admins when product stock drops below threshold
- Commerce shipping status update notifications to customers
- Commerce abandoned cart SMS reminders via cron

**Admin UI (7 Tabs)**
- **Dashboard**: read-only status overview, SMS stats, 30-day volume chart
- **Settings**: global on/off, test mode, country code, sender ID, OTP/2FA config, rate limits, language
- **Gateway**: API login/logout, cached balance/sender IDs/coverage, test SMS card
- **Templates**: multilingual message templates (EN/AR) with Drupal Token placeholders, character counter
- **Integrations**: Commerce event toggles (when submodule enabled)
- **Logs**: filterable SMS log with CSV export and clear
- **Help**: setup guide, feature overview, support links

**Developer Features**
- Full send flow: normalize, verify, clean, dedup, balance check, bulk batch (200/batch), ERR013 backoff
- Phone normalization: Arabic/Hindi digit conversion, leading zero handling, country code prepend
- Message cleaning: strip emoji, hidden Unicode chars, HTML tags
- Drupal Token system integration with custom tokens: `[kwtsms:otp-code]`, `[kwtsms:sender-id]`, `[kwtsms:balance]`
- Events for module integration: `kwtsms.otp_request` (CAPTCHA/validation) and `kwtsms.sms_send` (pre-send hooks)
- SMS Framework v2 gateway plugin via bridge submodule
- Log retention policy: auto-delete logs older than configurable days
- Daily cron sync for balance, sender IDs, and coverage
- Automatic expired OTP cleanup

## Requirements

- Drupal 10.3 or 11
- PHP 8.2+
- A [kwtSMS.com](https://www.kwtsms.com) account with API access

**Optional:**
- [Drupal Commerce](https://www.drupal.org/project/commerce): enable the `kwtsms_commerce` submodule for order notifications
- [SMS Framework](https://www.drupal.org/project/smsframework): enable the `kwtsms_smsframework` bridge submodule to use kwtSMS as an SMS Framework gateway
- [Token](https://www.drupal.org/project/token): adds a token browser UI for template editing

## Installation

```bash
composer require drupal/kwtsms
drush en kwtsms
```

For Commerce order notifications:

```bash
drush en kwtsms_commerce
```

## Configuration

Navigate to **Admin > Configuration > kwtSMS** (`/admin/config/kwtsms`).

1. **Gateway tab**: enter your kwtSMS API username and password, click **Login**
2. **Settings tab**: select your sender ID and default country code, enable SMS sending
3. **Templates tab**: customize message text for each notification type (English and Arabic)
4. **Settings > Authentication**: configure OTP login mode (disabled/primary/2FA) and password reset mode

## Module Structure

```
kwtsms/                          Base module (standalone)
  src/Service/                   Core services (gateway, normalizer, cleaner, logger, renderer)
  src/Authentication/            OTP provider, 2FA manager
  src/Form/                      All admin forms (7 tabs)
  src/Controller/                Dashboard, help, sync, CSV export
  src/Entity/                    SmsTemplate config entity
  src/EventSubscriber/           User events, cron
  config/install/                Default settings + 4 system templates
  translations/kwtsms.ar.po     Arabic translations
  modules/kwtsms_commerce/       Commerce submodule
    src/EventSubscriber/         Order transition event subscriber
    config/install/              4 Commerce templates + 3 new (low stock, shipping, abandoned cart)
  modules/kwtsms_smsframework/   SMS Framework bridge submodule
    src/Plugin/SmsGateway/       SMS Framework gateway plugin
```

## Submodules

**kwtsms_commerce** integrates with Drupal Commerce to send SMS on order events. Listens to order state transitions (placed, fulfilled, canceled), sends low stock alerts, shipping status updates, and abandoned cart reminders. Requires the Commerce module. Enable separately after the base module.

**kwtsms_smsframework** registers kwtSMS as an SMS Framework gateway plugin, allowing other modules that use SMS Framework to send messages through kwtSMS. Requires the [SMS Framework](https://www.drupal.org/project/smsframework) module.

## Multilingual Support

- Admin UI: English and Arabic translations included (`translations/kwtsms.ar.po`)
- SMS templates: separate EN and AR body text per template
- RTL support in admin CSS via logical properties
- Language selection: auto (user preference), force English, or force Arabic

## Security

- API password stored in Drupal State API (not in exportable config)
- OTP codes stored as bcrypt hashes
- Credentials stripped from all stored API responses and logs
- Generic error messages to prevent account/phone enumeration
- Built-in rate limiting and lockout for OTP abuse prevention
- All forms use Drupal's CSRF protection
- Parameterized database queries throughout

See [SECURITY.md](SECURITY.md) for vulnerability reporting.

## Roadmap

**v1.2 (next)**
- Bulk SMS send from admin UI
- Scheduled/marketing SMS campaigns
- Webform integration submodule
- IP blacklist/VPN filtering for OTP abuse prevention

**v1.3**
- SMS delivery analytics dashboard (success rates, trends)
- Audit log with export for compliance

## Support

- **Support portal**: [kwtsms.com/support.html](https://www.kwtsms.com/support.html)
- **FAQ**: [kwtsms.com/faq/](https://www.kwtsms.com/faq/)
- **Sender ID help**: [kwtsms.com/sender-id-help.html](https://www.kwtsms.com/sender-id-help.html)
- **Issues**: [github.com/boxlinknet/kwtsms-drupal/issues](https://github.com/boxlinknet/kwtsms-drupal/issues)

## License

GPL-2.0-or-later. See [LICENSE](LICENSE) for details.

## Maintainers

[kwtSMS](https://www.kwtsms.com)
