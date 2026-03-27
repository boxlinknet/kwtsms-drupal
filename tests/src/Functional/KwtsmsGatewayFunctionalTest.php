<?php

declare(strict_types=1);

namespace Drupal\Tests\kwtsms\Functional;

use Drupal\kwtsms\Service\KwtsmsGateway;
use Drupal\kwtsms\Service\MessageCleaner;
use Drupal\kwtsms\Service\PhoneNormalizer;
use Drupal\Tests\BrowserTestBase;

/**
 * Functional tests for kwtSMS gateway using real API with test=1.
 *
 * These tests hit the live kwtSMS API endpoint. They require:
 * - KWTSMS_USERNAME environment variable
 * - KWTSMS_PASSWORD environment variable
 *
 * Credits consumed are recoverable by deleting from the kwtSMS queue.
 * No SMS messages are actually delivered when test=1.
 *
 * @group kwtsms
 * @group kwtsms_functional
 */
class KwtsmsGatewayFunctionalTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['kwtsms'];

  /**
   * API username.
   */
  private string $apiUsername;

  /**
   * API password.
   */
  private string $apiPassword;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $username = getenv('KWTSMS_USERNAME');
    $password = getenv('KWTSMS_PASSWORD');

    if (empty($username) || empty($password)) {
      $this->markTestSkipped('KWTSMS_USERNAME and KWTSMS_PASSWORD environment variables required.');
    }

    $this->apiUsername = $username;
    $this->apiPassword = $password;
  }

  /**
   * Test login with valid credentials.
   */
  public function testLoginSuccess(): void {
    $gateway = \Drupal::service('kwtsms.gateway');
    $result = $gateway->login($this->apiUsername, $this->apiPassword);

    $this->assertTrue($result['success'], 'Login should succeed with valid credentials.');
    $this->assertNotNull($result['balance'], 'Balance should be returned.');
    $this->assertGreaterThanOrEqual(0, $result['balance'], 'Balance should be non-negative.');
    $this->assertTrue($gateway->isConnected(), 'Gateway should be connected after login.');

    // Verify cached data.
    $balance = $gateway->getCachedValue('balance');
    $this->assertNotNull($balance, 'Balance should be cached.');

    $senderIds = $gateway->getCachedValue('senderids');
    $this->assertIsArray($senderIds, 'Sender IDs should be cached as array.');

    $coverage = $gateway->getCachedValue('coverage');
    $this->assertNotNull($coverage, 'Coverage should be cached.');
  }

  /**
   * Test login with invalid credentials.
   */
  public function testLoginFailure(): void {
    $gateway = \Drupal::service('kwtsms.gateway');
    $result = $gateway->login('invalid_user', 'invalid_pass');

    $this->assertFalse($result['success'], 'Login should fail with invalid credentials.');
    $this->assertNotEmpty($result['message'], 'Error message should be returned.');
    $this->assertFalse($gateway->isConnected(), 'Gateway should not be connected.');
  }

  /**
   * Test sync after login.
   */
  public function testSync(): void {
    $gateway = \Drupal::service('kwtsms.gateway');
    $gateway->login($this->apiUsername, $this->apiPassword);

    $result = $gateway->sync();
    $this->assertTrue($result['success'], 'Sync should succeed.');
    $this->assertNotNull($result['balance'], 'Balance should be returned from sync.');
  }

  /**
   * Test send with test=1 to a test number.
   */
  public function testSendTestMode(): void {
    $gateway = \Drupal::service('kwtsms.gateway');
    $gateway->login($this->apiUsername, $this->apiPassword);

    // Enable module and set test mode.
    \Drupal::configFactory()->getEditable('kwtsms.settings')
      ->set('enabled', TRUE)
      ->set('test_mode', TRUE)
      ->set('sender_id', 'KWT-SMS')
      ->save();

    $result = $gateway->sendTestSms('96598765432', 'kwtSMS Drupal module test.');

    $this->assertTrue($result['success'], 'Test SMS should succeed with test=1.');
    $this->assertArrayHasKey('api_response', $result, 'API response should be returned.');
  }

  /**
   * Test send aborts when SMS is disabled.
   */
  public function testSendDisabled(): void {
    $gateway = \Drupal::service('kwtsms.gateway');
    $gateway->login($this->apiUsername, $this->apiPassword);

    \Drupal::configFactory()->getEditable('kwtsms.settings')
      ->set('enabled', FALSE)
      ->save();

    $result = $gateway->send('96598765432', 'Test message', 'test');

    $this->assertFalse($result['success'], 'Send should fail when SMS is disabled.');
    $this->assertNotEmpty($result['errors'], 'Should have an error about SMS being disabled.');
  }

  /**
   * Test send with invalid phone is skipped.
   */
  public function testSendInvalidPhone(): void {
    $gateway = \Drupal::service('kwtsms.gateway');
    $gateway->login($this->apiUsername, $this->apiPassword);

    \Drupal::configFactory()->getEditable('kwtsms.settings')
      ->set('enabled', TRUE)
      ->set('test_mode', TRUE)
      ->save();

    $result = $gateway->send('12345', 'Test', 'test');

    $this->assertFalse($result['success'], 'Send should fail with invalid phone.');
    $this->assertNotEmpty($result['errors'], 'Should have error about invalid numbers.');
  }

  /**
   * Test send with empty message after cleaning.
   */
  public function testSendEmptyMessage(): void {
    $gateway = \Drupal::service('kwtsms.gateway');
    $gateway->login($this->apiUsername, $this->apiPassword);

    \Drupal::configFactory()->getEditable('kwtsms.settings')
      ->set('enabled', TRUE)
      ->set('test_mode', TRUE)
      ->save();

    // Only emoji - should be empty after cleaning.
    $result = $gateway->send('96598765432', "\xF0\x9F\x98\x80\xF0\x9F\x98\x82", 'test');

    $this->assertFalse($result['success'], 'Send should fail with empty message.');
  }

  /**
   * Test logout clears credentials and cache.
   */
  public function testLogout(): void {
    $gateway = \Drupal::service('kwtsms.gateway');
    $gateway->login($this->apiUsername, $this->apiPassword);
    $this->assertTrue($gateway->isConnected());

    $gateway->logout();
    $this->assertFalse($gateway->isConnected(), 'Should be disconnected after logout.');
    $this->assertNull($gateway->getCachedValue('balance'), 'Balance cache should be cleared.');
    $this->assertNull($gateway->getCachedValue('senderids'), 'Sender IDs cache should be cleared.');
  }

  /**
   * Test PhoneNormalizer integration.
   */
  public function testPhoneNormalizerIntegration(): void {
    $normalizer = \Drupal::service('kwtsms.phone_normalizer');

    $this->assertSame('96598765432', $normalizer->normalize('+96598765432'));
    $this->assertSame('96598765432', $normalizer->normalize('0096598765432'));

    $result = $normalizer->verify('96598765432');
    $this->assertTrue($result['valid']);

    $result = $normalizer->verify('12345');
    $this->assertFalse($result['valid']);
  }

  /**
   * Test MessageCleaner integration.
   */
  public function testMessageCleanerIntegration(): void {
    $cleaner = \Drupal::service('kwtsms.message_cleaner');

    $this->assertSame('Hello world', $cleaner->clean("Hello \xF0\x9F\x98\x80 world"));
    $this->assertSame('Hello world', $cleaner->clean('<b>Hello</b> world'));
    $this->assertSame(1, $cleaner->pageCount('Test message'));
  }

  /**
   * Test admin pages are accessible.
   */
  public function testAdminPages(): void {
    $admin = $this->drupalCreateUser([], NULL, TRUE);
    $this->drupalLogin($admin);

    $paths = [
      '/admin/config/kwtsms',
      '/admin/config/kwtsms/settings',
      '/admin/config/kwtsms/gateway',
      '/admin/config/kwtsms/templates',
      '/admin/config/kwtsms/integrations',
      '/admin/config/kwtsms/logs',
      '/admin/config/kwtsms/help',
    ];

    foreach ($paths as $path) {
      $this->drupalGet($path);
      $this->assertSession()->statusCodeEquals(200);
    }
  }

}
