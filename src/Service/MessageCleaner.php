<?php

declare(strict_types=1);

namespace Drupal\kwtsms\Service;

/**
 * Cleans SMS message text before sending.
 *
 * Strips HTML tags, converts Arabic/Hindi digits to Latin, removes emoji
 * and hidden Unicode control characters, and collapses whitespace. Also
 * calculates SMS page counts using GSM-7 (Latin) and Unicode (Arabic)
 * encoding limits.
 */
class MessageCleaner {

  /**
   * GSM-7 single-page character limit.
   */
  private const GSM7_SINGLE = 160;

  /**
   * GSM-7 multipart segment character limit.
   */
  private const GSM7_MULTI = 153;

  /**
   * Unicode (UCS-2) single-page character limit.
   */
  private const UNICODE_SINGLE = 70;

  /**
   * Unicode (UCS-2) multipart segment character limit.
   */
  private const UNICODE_MULTI = 67;

  /**
   * Cleans SMS text by stripping HTML, non-Latin digits, emoji, and controls.
   *
   * Steps applied in order:
   *  1. Strip HTML tags (including script/style content via strip_tags).
   *  2. Convert Arabic-Indic (U+0660-U+0669) and Extended Arabic-Indic
   *     (U+06F0-U+06F9) digits to ASCII equivalents.
   *  3. Strip emoji in Unicode ranges: U+1F000-U+1FFFF, U+2600-U+27BF,
   *     U+FE00-U+FE0F, U+1F900-U+1F9FF, U+200D (ZWJ), U+E0020-U+E007F.
   *  4. Strip hidden control characters: U+200B-U+200F (zero-width/bidi),
   *     U+00AD (soft hyphen), U+FEFF (BOM), U+2028-U+2029 (line/paragraph
   *     separators), U+202A-U+202E (bidi embeddings), U+2060-U+2064 (word
   *     joiner and invisible operators).
   *  5. Collapse runs of multiple spaces to a single space.
   *  6. Trim leading and trailing whitespace.
   *
   * @param string $text
   *   The raw SMS text.
   *
   * @return string
   *   The cleaned SMS text.
   */
  public function clean(string $text): string {
    // Step 1: strip HTML tags.
    $text = strip_tags($text);

    // Step 2: convert Arabic-Indic and Extended Arabic-Indic digits.
    $text = $this->transliterateArabicDigits($text);

    // Step 3: strip emoji and variation selectors.
    // Ranges: U+1F000-U+1FFFF, U+2600-U+27BF, U+FE00-U+FE0F,
    // U+1F900-U+1F9FF, U+200D (ZWJ), U+E0020-U+E007F.
    $text = preg_replace(
      '/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}\x{FE00}-\x{FE0F}'
      . '\x{1F900}-\x{1F9FF}\x{200D}\x{E0020}-\x{E007F}]/u',
      '',
      $text,
    ) ?? $text;

    // Step 4: strip hidden Unicode control characters.
    // U+200B-U+200F: zero-width space, non-joiner, joiner, LTR/RTL marks.
    // U+00AD: soft hyphen.
    // U+FEFF: BOM / zero-width no-break space.
    // U+2028-U+2029: line separator, paragraph separator.
    // U+202A-U+202E: bidi embedding/override characters.
    // U+2060-U+2064: word joiner and invisible operators.
    $text = preg_replace(
      '/[\x{200B}-\x{200F}\x{00AD}\x{FEFF}\x{2028}-\x{2029}'
      . '\x{202A}-\x{202E}\x{2060}-\x{2064}]/u',
      '',
      $text,
    ) ?? $text;

    // Step 5: collapse multiple spaces to a single space.
    $text = preg_replace('/ {2,}/', ' ', $text) ?? $text;

    // Step 6: trim leading/trailing whitespace.
    return trim($text);
  }

  /**
   * Calculates the number of SMS pages required to send the given text.
   *
   * Uses GSM-7 encoding limits for ASCII-only messages and Unicode (UCS-2)
   * limits when the message contains characters outside the ASCII printable
   * range. Returns 0 for empty strings.
   *
   * GSM-7 limits: 160 chars for a single page; 153 chars per part when
   * multipart. Unicode limits: 70 chars single page; 67 chars per part
   * when multipart.
   *
   * @param string $text
   *   The SMS text (should already be cleaned).
   *
   * @return int
   *   The number of SMS pages, or 0 if the text is empty.
   */
  public function pageCount(string $text): int {
    if ($text === '') {
      return 0;
    }

    // mb_strlen returns the number of characters (code points), not bytes.
    $length = mb_strlen($text, 'UTF-8');

    if ($this->isUnicode($text)) {
      $single = self::UNICODE_SINGLE;
      $multi  = self::UNICODE_MULTI;
    }
    else {
      $single = self::GSM7_SINGLE;
      $multi  = self::GSM7_MULTI;
    }

    if ($length <= $single) {
      return 1;
    }

    return (int) ceil($length / $multi);
  }

  /**
   * Determines whether the text requires Unicode (UCS-2) encoding.
   *
   * Returns TRUE if any character falls outside the printable ASCII range
   * (0x20-0x7E) plus newline (0x0A) and carriage return (0x0D).
   *
   * @param string $text
   *   The SMS text to inspect.
   *
   * @return bool
   *   TRUE when the text contains non-ASCII characters; FALSE for GSM-7 safe
   *   text.
   */
  public function isUnicode(string $text): bool {
    // A GSM-7-safe string contains only printable ASCII plus CR/LF.
    return (bool) preg_match('/[^\x20-\x7E\x0A\x0D]/', $text);
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
   *   The string with all Arabic-Indic digits replaced by ASCII equivalents.
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
