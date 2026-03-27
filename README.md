# kwtSMS

SMS gateway integration with kwtsms.com for Drupal 10.3+ and 11. Provides OTP login, two-factor authentication, password reset via SMS, user registration notifications, and Commerce order alerts.

## Requirements

- Drupal 10.3 or 11
- PHP 8.2+

Optional:

- [Drupal Commerce](https://www.drupal.org/project/commerce) (for Commerce order notifications via the `kwtsms_commerce` submodule)
- [Token](https://www.drupal.org/project/token) (for the template token browser in the admin UI)

## Installation

Install the module using Composer:

```
composer require drupal/kwtsms
```

Enable the module:

```
drush en kwtsms
```

For Commerce order notifications, also enable the submodule:

```
drush en kwtsms_commerce
```

## Configuration

Go to **Admin > Configuration > kwtSMS**.

1. **Gateway tab:** Enter your API credentials and click Login to authenticate with kwtsms.com.
2. **Settings tab:** Configure your sender ID, default country code, and enable SMS sending.
3. **Templates tab:** Customize message text for each notification type.
4. **OTP (two-factor authentication):** Go to Settings and configure the Authentication section.

## Features

- OTP login: use SMS as the primary login method or as a second factor (2FA).
- Password reset via SMS: send reset codes by SMS only, Email+SMS, or Email only.
- User registration notifications: notify customers and admins by SMS when a new account is created.
- Admin alerts for new user registrations.
- Commerce order notifications: order placed, status updates, payment confirmations.
- Multilingual message templates (English and Arabic).
- SMS log with filtering, pagination, and CSV export.
- Dashboard with SMS stats and a 30-day volume chart.
- Daily cron sync for account balance, sender IDs, and coverage data.
- Automatic expired OTP cleanup via cron.
- Rate limiting per phone number and per IP for OTP requests.
- Arabic translations for all module strings, with RTL support in the admin UI.

## Submodules

**kwtsms_commerce** integrates with Drupal Commerce to send SMS notifications for order events. Requires the Commerce module. Enable separately after installing the base module.

## Support

- Support portal: https://kwtsms.com/support.html
- FAQ: https://kwtsms.com/faq_all.php
- Email: support@kwtsms.com

## Maintainers

kwtSMS Team (support@kwtsms.com)
