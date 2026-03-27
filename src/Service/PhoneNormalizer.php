<?php

declare(strict_types=1);

namespace Drupal\kwtsms\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Normalizes and validates phone numbers for SMS delivery.
 *
 * Converts Arabic/Hindi digits to Latin, strips formatting characters,
 * handles local numbers by prepending the configured country code, and
 * validates E.164 compliance including country-specific length rules.
 */
class PhoneNormalizer {

  /**
   * Total E.164 digit lengths keyed by country prefix.
   *
   * Each value is the exact total number of digits expected (including the
   * country code itself) for a valid number in that country.
   *
   * @var array<string, int>
   */
  private const COUNTRY_LENGTHS = [
    '965' => 11,
    '966' => 12,
    '971' => 12,
    '973' => 11,
    '974' => 11,
    '968' => 11,
    '962' => 12,
    '961' => 11,
    '20'  => 12,
    '964' => 13,
    '1'   => 11,
    '44'  => 12,
    '91'  => 12,
  ];

  /**
   * The default country code (digits only, no plus sign).
   */
  private string $defaultCountryCode;

  /**
   * Constructs a PhoneNormalizer instance.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory service.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {
    $this->defaultCountryCode = (string) $this->configFactory
      ->get('kwtsms.settings')
      ->get('default_country_code') ?: '965';
  }

  /**
   * Normalizes a phone number to a plain E.164 digit string (no plus sign).
   *
   * Steps applied in order:
   *  1. Convert Arabic-Indic and Extended Arabic-Indic digits to ASCII digits.
   *  2. Strip all non-digit characters (spaces, dashes, plus, parentheses).
   *  3. Remove leading double-zero trunk prefix (00...).
   *  4. Remove a single leading zero for local numbers with <=10 digits.
   *  5. Prepend the default country code when the remaining digit count is <=10.
   *
   * @param string $phone
   *   The raw phone number string.
   *
   * @return string
   *   The normalized digit string.
   */
  public function normalize(string $phone): string {
    // Step 1: transliterate Arabic-Indic (U+0660-U+0669) and
    // Extended Arabic-Indic (U+06F0-U+06F9) digits to ASCII equivalents.
    $phone = $this->transliterateArabicDigits($phone);

    // Step 2: strip every character that is not a digit.
    $phone = preg_replace('/[^0-9]/', '', $phone);

    // Step 3: remove leading international trunk prefix "00".
    if (str_starts_with($phone, '00')) {
      $phone = substr($phone, 2);
    }

    // Step 4 & 5: if the number looks local (10 or fewer digits), strip a
    // single leading zero and then prepend the default country code.
    if (strlen($phone) <= 10) {
      if (str_starts_with($phone, '0')) {
        $phone = substr($phone, 1);
      }
      $phone = $this->defaultCountryCode . $phone;
    }

    return $phone;
  }

  /**
   * Validates a normalized phone number.
   *
   * The input should already be normalized (digits only, no plus sign). The
   * method checks:
   *  - The string is non-empty.
   *  - It contains only digits.
   *  - Its length is within the E.164 range (8-15 digits, country code
   *    included).
   *  - Where the country code is recognized, the total length matches exactly.
   *
   * @param string $phone
   *   The normalized phone number to validate.
   *
   * @return array{valid: bool, reason: string}
   *   An associative array with keys 'valid' (bool) and 'reason' (string).
   */
  public function verify(string $phone): array {
    if ($phone === '') {
      return ['valid' => FALSE, 'reason' => 'Phone number is empty.'];
    }

    if (!ctype_digit($phone)) {
      return ['valid' => FALSE, 'reason' => 'Phone number contains non-digit characters.'];
    }

    $length = strlen($phone);

    if ($length < 8 || $length > 15) {
      return [
        'valid'  => FALSE,
        'reason' => sprintf('Phone number length %d is outside the valid E.164 range (8-15).', $length),
      ];
    }

    $countryCode = $this->detectCountryCode($phone);
    if ($countryCode !== NULL) {
      $expected = self::COUNTRY_LENGTHS[$countryCode];
      if ($length !== $expected) {
        return [
          'valid'  => FALSE,
          'reason' => sprintf(
            'Number with country code +%s must be %d digits total; got %d.',
            $countryCode,
            $expected,
            $length,
          ),
        ];
      }
    }

    return ['valid' => TRUE, 'reason' => ''];
  }

  /**
   * Detects the country code prefix present in a normalized phone number.
   *
   * Checks known prefixes longest-first so that multi-digit codes like '965'
   * take precedence over shorter codes like '9'.
   *
   * @param string $phone
   *   A normalized phone number (digits only, no plus sign).
   *
   * @return string|null
   *   The matching country code string, or NULL if none is recognized.
   */
  public function detectCountryCode(string $phone): ?string {
    // Sort by prefix length descending to prefer the longest match.
    $prefixes = array_keys(self::COUNTRY_LENGTHS);
    usort($prefixes, static fn(string $a, string $b): int => strlen($b) - strlen($a));

    foreach ($prefixes as $prefix) {
      if (str_starts_with($phone, $prefix)) {
        return $prefix;
      }
    }

    return NULL;
  }

  /**
   * Converts Arabic-Indic and Extended Arabic-Indic digits to ASCII digits.
   *
   * Handles:
   *  - Arabic-Indic digits: U+0660 (٠) through U+0669 (٩).
   *  - Extended Arabic-Indic digits: U+06F0 (۰) through U+06F9 (۹).
   *
   * @param string $input
   *   A string possibly containing Arabic-Indic digit characters.
   *
   * @return string
   *   The string with all Arabic-Indic digits replaced by their ASCII
   *   equivalents.
   */
  private function transliterateArabicDigits(string $input): string {
    // Arabic-Indic: ٠١٢٣٤٥٦٧٨٩ (U+0660-U+0669)
    $arabicIndic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    // Extended Arabic-Indic: ۰۱۲۳۴۵۶۷۸۹ (U+06F0-U+06F9)
    $extendedArabicIndic = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $latin = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    $input = str_replace($arabicIndic, $latin, $input);
    $input = str_replace($extendedArabicIndic, $latin, $input);

    return $input;
  }

}
