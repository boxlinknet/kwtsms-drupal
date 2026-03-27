<?php

declare(strict_types=1);

namespace Drupal\Tests\kwtsms\Unit\Service;

use Drupal\kwtsms\Service\MessageCleaner;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for MessageCleaner.
 *
 * @coversDefaultClass \Drupal\kwtsms\Service\MessageCleaner
 * @group kwtsms
 */
class MessageCleanerTest extends UnitTestCase {

  /**
   * The MessageCleaner instance under test.
   */
  private MessageCleaner $cleaner;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->cleaner = new MessageCleaner();
  }

  /**
   * Tests that emoji are removed from the text.
   *
   * @covers ::clean
   */
  public function testStripEmoji(): void {
    $result = $this->cleaner->clean("Hello \u{1F600} world");
    $this->assertSame('Hello world', $result);
  }

  /**
   * Tests that HTML tags and script content are stripped.
   *
   * @covers ::clean
   */
  public function testStripHtml(): void {
    $result = $this->cleaner->clean('<b>Hello</b> <script>alert(1)</script>world');
    $this->assertSame('Hello world', $result);
  }

  /**
   * Tests that zero-width and soft-hyphen control characters are removed.
   *
   * @covers ::clean
   */
  public function testStripZeroWidthChars(): void {
    // U+200B zero-width space, U+00AD soft hyphen, U+FEFF BOM.
    $result = $this->cleaner->clean("He\u{200B}l\u{00AD}l\u{FEFF}o");
    $this->assertSame('Hello', $result);
  }

  /**
   * Tests conversion of Arabic-Indic digits (U+0660-U+0669) to ASCII digits.
   *
   * @covers ::clean
   */
  public function testConvertArabicDigits(): void {
    // ١٢٣٤ are Arabic-Indic digits U+0661-U+0664.
    $result = $this->cleaner->clean("Code: \u{0661}\u{0662}\u{0663}\u{0664}");
    $this->assertSame('Code: 1234', $result);
  }

  /**
   * Tests that newlines are preserved through cleaning.
   *
   * @covers ::clean
   */
  public function testPreservesNewlines(): void {
    $result = $this->cleaner->clean("Line 1\nLine 2");
    $this->assertSame("Line 1\nLine 2", $result);
  }

  /**
   * Tests that multiple spaces are collapsed to one and edges are trimmed.
   *
   * @covers ::clean
   */
  public function testTrimWhitespace(): void {
    $result = $this->cleaner->clean('  Hello   world  ');
    $this->assertSame('Hello world', $result);
  }

  /**
   * Tests that a string consisting only of emoji cleans to an empty string.
   *
   * @covers ::clean
   */
  public function testEmptyAfterCleaning(): void {
    // U+1F600 grinning face emoji.
    $result = $this->cleaner->clean("\u{1F600}");
    $this->assertSame('', $result);
  }

  /**
   * Tests SMS page count calculations for English (GSM-7) and Arabic (Unicode).
   *
   * GSM-7 limits: 160 chars per single page, 153 chars per part multipart.
   * Unicode limits: 70 chars per single page, 67 chars per part multipart.
   *
   * @covers ::pageCount
   * @covers ::isUnicode
   *
   * @dataProvider providerPageCount
   */
  public function testPageCount(string $text, int $expectedPages): void {
    $this->assertSame($expectedPages, $this->cleaner->pageCount($text));
  }

  /**
   * Data provider for testPageCount.
   *
   * @return array<string, array{string, int}>
   */
  public static function providerPageCount(): array {
    // U+0627 is Arabic letter alef, which forces Unicode encoding.
    $alef = "\u{0627}";

    return [
      'GSM-7 160 chars = 1 page'    => [str_repeat('a', 160), 1],
      'GSM-7 161 chars = 2 pages'   => [str_repeat('a', 161), 2],
      'GSM-7 306 chars = 2 pages'   => [str_repeat('a', 306), 2],
      'GSM-7 307 chars = 3 pages'   => [str_repeat('a', 307), 3],
      'Unicode 70 chars = 1 page'   => [str_repeat($alef, 70), 1],
      'Unicode 71 chars = 2 pages'  => [str_repeat($alef, 71), 2],
    ];
  }

}
