# Security Policy

## Reporting a Vulnerability

If you discover a security vulnerability in the kwtSMS module, please report it responsibly.

**Email:** support@kwtsms.com

**What to include:**
- Description of the vulnerability
- Steps to reproduce
- Potential impact
- Suggested fix (if any)

**Response time:** We aim to acknowledge reports within 48 hours and provide a fix within 7 days for critical issues.

## Security Practices

- API credentials are stored in Drupal's State API (not in exportable config)
- OTP codes are stored as bcrypt hashes, never in plain text
- All forms use Drupal's built-in CSRF protection
- Database queries use parameterized statements (no string interpolation)
- Phone inputs are sanitized through the PhoneNormalizer service
- Message content is cleaned through the MessageCleaner service
- Error messages are generic to prevent account/phone enumeration
- Rate limiting protects against OTP abuse
- Credentials are stripped from all stored API responses and logs
