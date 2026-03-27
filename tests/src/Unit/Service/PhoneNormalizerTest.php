<?php

declare(strict_types=1);

namespace Drupal\Tests\kwtsms\Unit\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\kwtsms\Service\PhoneNormalizer;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for PhoneNormalizer.
 *
 * @coversDefaultClass \Drupal\kwtsms\Service\PhoneNormalizer
 * @group kwtsms
 */
class PhoneNormalizerTest extends UnitTestCase {

  /**
   * The phone normalizer under test.
   */
  private PhoneNormalizer $normalizer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('default_country_code')
      ->willReturn('965');

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('kwtsms.settings')
      ->willReturn($config);

    $this->normalizer = new PhoneNormalizer($configFactory);
  }

  /**
   * Data provider for normalize() tests.
   *
   * @return array<string, array{string, string}>
   *   Test cases keyed by label, each with input and expected normalized phone.
   */
  public static function normalizeProvider(): array {
    return [
      'international digits only' => ['96598765432', '96598765432'],
      'with plus prefix'          => ['+96598765432', '96598765432'],
      'with 00 prefix'            => ['0096598765432', '96598765432'],
      'with spaces'               => ['965 9876 5432', '96598765432'],
      'with dashes'               => ['965-9876-5432', '96598765432'],
      'local with leading zero'   => ['098765432', '96598765432'],
      'arabic digits'             => ['٩٦٥٩٨٧٦٥٤٣٢', '96598765432'],
      'ksa local'                 => ['0598765432', '965598765432'],
      'mixed junk'                => [' +00 965-9876-5432 ', '96598765432'],
    ];
  }

  /**
   * Tests normalize() with various input formats.
   *
   * @covers ::normalize
   * @dataProvider normalizeProvider
   */
  public function testNormalize(string $input, string $expected): void {
    $this->assertSame($expected, $this->normalizer->normalize($input));
  }

  /**
   * Data provider for verify() tests.
   *
   * @return array<string, array{string, bool}>
   *   Test cases keyed by label, each with phone and expected validity.
   */
  public static function verifyProvider(): array {
    return [
      'valid kuwait' => ['96598765432', TRUE],
      'valid ksa'    => ['966598765432', TRUE],
      'valid uae'    => ['971501234567', TRUE],
      'too short'    => ['123456', FALSE],
      'too long'     => ['9659876543212345', FALSE],
      'empty'        => ['', FALSE],
    ];
  }

  /**
   * Tests verify() with various normalized phone numbers.
   *
   * @covers ::verify
   * @dataProvider verifyProvider
   */
  public function testVerify(string $phone, bool $expectedValid): void {
    $result = $this->normalizer->verify($phone);
    $this->assertArrayHasKey('valid', $result);
    $this->assertArrayHasKey('reason', $result);
    $this->assertSame($expectedValid, $result['valid']);
  }

  /**
   * Tests that verify() returns a non-empty reason string when invalid.
   *
   * @covers ::verify
   */
  public function testVerifyInvalidHasReason(): void {
    $result = $this->normalizer->verify('123');
    $this->assertFalse($result['valid']);
    $this->assertNotEmpty($result['reason']);
  }

  /**
   * Tests that verify() returns an empty reason string when valid.
   *
   * @covers ::verify
   */
  public function testVerifyValidHasEmptyReason(): void {
    $result = $this->normalizer->verify('96598765432');
    $this->assertTrue($result['valid']);
    $this->assertSame('', $result['reason']);
  }

  /**
   * Data provider for detectCountryCode() tests.
   *
   * @return array<string, array{string, string|null}>
   *   Test cases keyed by label, each with phone and expected country code.
   */
  public static function detectCountryCodeProvider(): array {
    return [
      'kuwait'    => ['96598765432', '965'],
      'ksa'       => ['966501234567', '966'],
      'uae'       => ['971501234567', '971'],
      'egypt'     => ['201234567890', '20'],
      'us'        => ['12025551234', '1'],
      'unknown'   => ['555123456', NULL],
    ];
  }

  /**
   * Tests detectCountryCode() for known and unknown prefixes.
   *
   * @covers ::detectCountryCode
   * @dataProvider detectCountryCodeProvider
   */
  public function testDetectCountryCode(string $phone, ?string $expected): void {
    $this->assertSame($expected, $this->normalizer->detectCountryCode($phone));
  }

}
