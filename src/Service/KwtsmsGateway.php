<?php

declare(strict_types=1);

namespace Drupal\kwtsms\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Core gateway service. Single point of contact with the kwtSMS REST API.
 *
 * Handles login/logout, sync, balance caching, phone validation, message
 * cleaning, single sends, and bulk sends with ERR013 backoff.
 *
 * All API communication uses POST with Content-Type: application/json.
 */
class KwtsmsGateway {

  /**
   * Base URL for all kwtSMS API endpoints.
   */
  private const API_BASE = 'https://www.kwtsms.com/API/';

  /**
   * Maximum number of recipients per single API send request.
   */
  private const MAX_BATCH = 200;

  /**
   * Delay in microseconds between bulk batches (0.2 seconds).
   */
  private const BATCH_DELAY_MS = 200000;

  /**
   * Backoff delay sequence in seconds for ERR013 (queue full) retries.
   *
   * @var int[]
   */
  private const ERR013_BACKOFF = [30, 60, 120];

  /**
   * Constructs a KwtsmsGateway instance.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The kwtsms logger channel.
   * @param \Drupal\kwtsms\Service\PhoneNormalizer $phoneNormalizer
   *   The phone normalizer service.
   * @param \Drupal\kwtsms\Service\MessageCleaner $messageCleaner
   *   The message cleaner service.
   */
  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StateInterface $state,
    private readonly Connection $database,
    private readonly LoggerInterface $logger,
    private readonly PhoneNormalizer $phoneNormalizer,
    private readonly MessageCleaner $messageCleaner,
  ) {}

  // ---------------------------------------------------------------------------
  // Public API
  // ---------------------------------------------------------------------------

  /**
   * Sends an SMS message to one or more recipients.
   *
   * Steps:
   *  1. Check global enabled flag; abort if disabled.
   *  2. Check isConnected(); abort if not connected.
   *  3. Read test_mode from config.
   *  4. Clean message via MessageCleaner; abort if empty after cleaning.
   *  5. Normalize and verify each recipient; skip and log invalid ones.
   *  6. Check coverage; skip and log numbers whose prefix is not covered.
   *  7. Deduplicate via array_unique.
   *  8. Check cached balance; abort if <=0 (unless options['skip_balance_check']).
   *  9. Chunk to bulkSend() when >200 recipients, else call apiSend() directly.
   * 10. Process results, log per-recipient via SmsLogger.
   *
   * @param array|string $recipients
   *   A single phone number string or an array of phone number strings.
   * @param string $message
   *   The SMS message body.
   * @param string $eventType
   *   An event type label stored in the log (e.g. 'manual', 'registration').
   * @param array $options
   *   Optional flags:
   *   - skip_balance_check (bool): Skip the cached-balance guard.
   *
   * @return array{success: bool, results: array, errors: array}
   *   Associative array with keys 'success', 'results', and 'errors'.
   */
  public function send(array|string $recipients, string $message, string $eventType = 'manual', array $options = []): array {
    $config = $this->configFactory->get('kwtsms.settings');

    // Step 1: global enabled guard.
    if (!$config->get('enabled')) {
      $this->logger->warning('kwtSMS: send() aborted — module is disabled.');
      return ['success' => FALSE, 'results' => [], 'errors' => ['Module is disabled.']];
    }

    // Step 2: connectivity guard.
    if (!$this->isConnected()) {
      $this->logger->warning('kwtSMS: send() aborted — gateway not connected.');
      return ['success' => FALSE, 'results' => [], 'errors' => ['Gateway not connected.']];
    }

    $testMode = (bool) $config->get('test_mode');
    $senderId = (string) ($config->get('sender_id') ?? '');

    // Step 4: clean message.
    $message = $this->messageCleaner->clean($message);
    if ($message === '') {
      $this->logger->warning('kwtSMS: send() aborted — message is empty after cleaning.');
      return ['success' => FALSE, 'results' => [], 'errors' => ['Message is empty after cleaning.']];
    }

    // Step 5 & 6: normalize, verify, and check coverage.
    $recipientList = is_array($recipients) ? $recipients : [$recipients];
    $validNumbers  = [];
    $errors        = [];
    $coverage      = $this->getCachedCoverage();

    foreach ($recipientList as $raw) {
      $normalized = $this->phoneNormalizer->normalize((string) $raw);
      $verification = $this->phoneNormalizer->verify($normalized);

      if (!$verification['valid']) {
        $msg = sprintf('Skipping "%s": %s', $raw, $verification['reason']);
        $this->logger->notice('kwtSMS: @msg', ['@msg' => $msg]);
        $errors[] = $msg;
        continue;
      }

      if ($coverage !== NULL && !$this->isCovered($normalized, $coverage)) {
        $msg = sprintf('Skipping "%s": country prefix not in coverage.', $normalized);
        $this->logger->notice('kwtSMS: @msg', ['@msg' => $msg]);
        $errors[] = $msg;
        continue;
      }

      $validNumbers[] = $normalized;
    }

    if (empty($validNumbers)) {
      return ['success' => FALSE, 'results' => [], 'errors' => array_merge($errors, ['No valid recipients.'])];
    }

    // Step 7: deduplicate.
    $validNumbers = array_values(array_unique($validNumbers));

    // Step 8: balance guard.
    if (empty($options['skip_balance_check'])) {
      $balance = $this->getCachedBalance();
      if ($balance !== NULL && $balance <= 0) {
        $this->logger->error('kwtSMS: send() aborted — balance is zero or negative.');
        return ['success' => FALSE, 'results' => [], 'errors' => array_merge($errors, ['Insufficient balance.'])];
      }
    }

    // Step 9: send.
    if (count($validNumbers) > self::MAX_BATCH) {
      $batchResult = $this->bulkSend($validNumbers, $message, $senderId, $testMode, $eventType, $options);
      return [
        'success' => $batchResult['success'],
        'results' => $batchResult['results'],
        'errors'  => array_merge($errors, $batchResult['errors']),
      ];
    }

    $mobile    = implode(',', $validNumbers);
    $apiResult = $this->apiSend($mobile, $message, $senderId, $testMode);
    $processed = $this->processApiResult($apiResult, $validNumbers, $message, $senderId, $testMode, $eventType, $options);

    return [
      'success' => $processed['success'],
      'results' => $processed['results'],
      'errors'  => array_merge($errors, $processed['errors']),
    ];
  }

  /**
   * Authenticates with the kwtSMS API and stores credentials on success.
   *
   * On success: persists the username to config, stores the password in the
   * State API, sets kwtsms.gateway_connected to TRUE, caches the balance,
   * fetches and caches sender IDs, and fetches and caches coverage.
   *
   * @param string $username
   *   The kwtSMS API username.
   * @param string $password
   *   The kwtSMS API password.
   *
   * @return array{success: bool, message: string, balance: int|null}
   *   Result array with keys 'success', 'message', and 'balance'.
   */
  public function login(string $username, string $password): array {
    $payload  = ['username' => $username, 'password' => $password];
    $response = $this->apiCall('balance/', $payload);

    if ($response === NULL) {
      return ['success' => FALSE, 'message' => 'API request failed. Check network connectivity.', 'balance' => NULL];
    }

    if (($response['result'] ?? '') !== 'OK') {
      $code        = $response['code'] ?? 'UNKNOWN';
      $description = $response['description'] ?? 'Unknown error.';
      return ['success' => FALSE, 'message' => sprintf('%s: %s', $code, $description), 'balance' => NULL];
    }

    $balance = isset($response['available']) ? (int) $response['available'] : NULL;

    // Persist credentials.
    $this->configFactory->getEditable('kwtsms.settings')->set('api_username', $username)->save();
    $this->state->set('kwtsms.api_password', $password);
    $this->state->set('kwtsms.gateway_connected', TRUE);

    // Cache balance.
    if ($balance !== NULL) {
      $this->setCachedValue('balance', $balance);
    }

    // Fetch and cache sender IDs.
    $senderResponse = $this->apiCall('senderid/', $payload);
    if ($senderResponse !== NULL && ($senderResponse['result'] ?? '') === 'OK') {
      $this->setCachedValue('senderids', $senderResponse['senderid'] ?? []);
    }

    // Fetch and cache coverage (extract prefixes array only).
    $coverageResponse = $this->apiCall('coverage/', $payload);
    if ($coverageResponse !== NULL && ($coverageResponse['result'] ?? '') === 'OK') {
      $this->setCachedValue('coverage', $coverageResponse['prefixes'] ?? []);
    }

    $this->logger->info('kwtSMS: gateway connected as @user.', ['@user' => $username]);

    return ['success' => TRUE, 'message' => 'Connected successfully.', 'balance' => $balance];
  }

  /**
   * Clears all credentials and cached data, disconnecting the gateway.
   *
   * Removes api_username and sender_id from config, deletes the API password
   * and connected state from the State API, and truncates the kwtsms_cache
   * table.
   */
  public function logout(): void {
    $this->configFactory->getEditable('kwtsms.settings')
      ->clear('api_username')
      ->clear('sender_id')
      ->save();

    $this->state->delete('kwtsms.api_password');
    $this->state->set('kwtsms.gateway_connected', FALSE);

    $this->database->truncate('kwtsms_cache')->execute();

    $this->logger->info('kwtSMS: gateway disconnected.');
  }

  /**
   * Returns whether the gateway is currently connected.
   *
   * @return bool
   *   TRUE when the kwtsms.gateway_connected state flag is set.
   */
  public function isConnected(): bool {
    return (bool) $this->state->get('kwtsms.gateway_connected', FALSE);
  }

  /**
   * Refreshes balance, sender IDs, and coverage from the API.
   *
   * Does nothing and returns a failure result when not connected.
   *
   * @return array{success: bool, balance: int|null}
   *   Result array with keys 'success' and 'balance'.
   */
  public function sync(): array {
    if (!$this->isConnected()) {
      return ['success' => FALSE, 'balance' => NULL];
    }

    $credentials = $this->getCredentials();
    $balance     = NULL;

    $balanceResponse = $this->apiCall('balance/', $credentials);
    if ($balanceResponse !== NULL && ($balanceResponse['result'] ?? '') === 'OK') {
      $balance = isset($balanceResponse['available']) ? (int) $balanceResponse['available'] : NULL;
      if ($balance !== NULL) {
        $this->setCachedValue('balance', $balance);
      }
    }

    $senderResponse = $this->apiCall('senderid/', $credentials);
    if ($senderResponse !== NULL && ($senderResponse['result'] ?? '') === 'OK') {
      $this->setCachedValue('senderids', $senderResponse['senderid'] ?? []);
    }

    $coverageResponse = $this->apiCall('coverage/', $credentials);
    if ($coverageResponse !== NULL && ($coverageResponse['result'] ?? '') === 'OK') {
      $this->setCachedValue('coverage', $coverageResponse['prefixes'] ?? []);
    }

    $this->logger->info('kwtSMS: sync completed. Balance: @balance.', ['@balance' => $balance ?? 'unknown']);

    return ['success' => TRUE, 'balance' => $balance];
  }

  /**
   * Retrieves a cached value from the kwtsms_cache table.
   *
   * @param string $key
   *   The cache key.
   *
   * @return mixed
   *   The decoded value, or NULL if the key does not exist.
   */
  public function getCachedValue(string $key): mixed {
    $result = $this->database->select('kwtsms_cache', 'c')
      ->fields('c', ['cache_value'])
      ->condition('c.cache_key', $key)
      ->execute()
      ->fetchField();

    if ($result === FALSE || $result === NULL) {
      return NULL;
    }

    return json_decode((string) $result, TRUE);
  }

  /**
   * Stores a value in the kwtsms_cache table using UPSERT semantics.
   *
   * @param string $key
   *   The cache key.
   * @param mixed $value
   *   The value to store (will be JSON-encoded).
   */
  public function setCachedValue(string $key, mixed $value): void {
    $encoded = json_encode($value);
    $now     = \Drupal::time()->getRequestTime();

    $this->database->merge('kwtsms_cache')
      ->key('cache_key', $key)
      ->fields([
        'cache_key'   => $key,
        'cache_value' => $encoded,
        'updated'     => $now,
      ])
      ->execute();
  }

  /**
   * Returns the Unix timestamp when a cache key was last updated.
   *
   * @param string $key
   *   The cache key.
   *
   * @return int|null
   *   The Unix timestamp, or NULL if the key does not exist.
   */
  public function getCacheTimestamp(string $key): ?int {
    $result = $this->database->select('kwtsms_cache', 'c')
      ->fields('c', ['updated'])
      ->condition('c.cache_key', $key)
      ->execute()
      ->fetchField();

    return ($result !== FALSE && $result !== NULL) ? (int) $result : NULL;
  }

  /**
   * Sends a test SMS to a single phone number.
   *
   * Always uses test mode regardless of the module's test_mode config.
   * Normalizes and verifies the phone number and cleans the message before
   * calling the API.
   *
   * @param string $phone
   *   The recipient phone number.
   * @param string $message
   *   The SMS message body.
   *
   * @return array{success: bool, api_response: array, message: string}
   *   Result array with keys 'success', 'api_response', and 'message'.
   */
  public function sendTestSms(string $phone, string $message): array {
    $config   = $this->configFactory->get('kwtsms.settings');
    $senderId = (string) ($config->get('sender_id') ?? '');

    $normalized   = $this->phoneNormalizer->normalize($phone);
    $verification = $this->phoneNormalizer->verify($normalized);

    if (!$verification['valid']) {
      return [
        'success'      => FALSE,
        'api_response' => [],
        'message'      => sprintf('Invalid phone number: %s', $verification['reason']),
      ];
    }

    $cleanMessage = $this->messageCleaner->clean($message);
    if ($cleanMessage === '') {
      return [
        'success'      => FALSE,
        'api_response' => [],
        'message'      => 'Message is empty after cleaning.',
      ];
    }

    $apiResult = $this->apiSend($normalized, $cleanMessage, $senderId, TRUE);

    if ($apiResult === NULL) {
      return [
        'success'      => FALSE,
        'api_response' => [],
        'message'      => 'API request failed.',
      ];
    }

    $ok = ($apiResult['result'] ?? '') === 'OK';

    return [
      'success'      => $ok,
      'api_response' => $apiResult,
      'message'      => $ok
        ? sprintf('Test SMS queued (test mode). Msg-ID: %s', $apiResult['msg-id'] ?? 'N/A')
        : sprintf('%s: %s', $apiResult['code'] ?? 'ERROR', $apiResult['description'] ?? 'Unknown error.'),
    ];
  }

  // ---------------------------------------------------------------------------
  // Private helpers
  // ---------------------------------------------------------------------------

  /**
   * Sends to more than MAX_BATCH recipients by chunking into batches.
   *
   * Inserts a BATCH_DELAY_MS microsecond pause between batches. Delegates
   * ERR013 (queue full) responses to retryWithBackoff().
   *
   * @param string[] $numbers
   *   Full list of normalized recipient numbers.
   * @param string $message
   *   Cleaned message text.
   * @param string $senderId
   *   Sender ID string.
   * @param bool $testMode
   *   Whether to send in test mode.
   * @param string $eventType
   *   Event type label for logging.
   * @param array $options
   *   Original send() options array.
   *
   * @return array{success: bool, results: array, errors: array}
   *   Aggregated result across all batches.
   */
  private function bulkSend(array $numbers, string $message, string $senderId, bool $testMode, string $eventType, array $options): array {
    $chunks  = array_chunk($numbers, self::MAX_BATCH);
    $results = [];
    $errors  = [];
    $allOk   = TRUE;

    foreach ($chunks as $index => $batch) {
      if ($index > 0) {
        usleep(self::BATCH_DELAY_MS);
      }

      $mobile    = implode(',', $batch);
      $apiResult = $this->apiSend($mobile, $message, $senderId, $testMode);

      // Handle ERR013 (queue full) with exponential backoff.
      if ($apiResult !== NULL && ($apiResult['code'] ?? '') === 'ERR013') {
        $apiResult = $this->retryWithBackoff($batch, $message, $senderId, $testMode);
      }

      $processed = $this->processApiResult($apiResult, $batch, $message, $senderId, $testMode, $eventType, $options);

      if (!$processed['success']) {
        $allOk = FALSE;
      }

      $results = array_merge($results, $processed['results']);
      $errors  = array_merge($errors, $processed['errors']);
    }

    return ['success' => $allOk, 'results' => $results, 'errors' => $errors];
  }

  /**
   * Retries a batch after ERR013 (queue full) using the ERR013_BACKOFF delays.
   *
   * Sleeps for each backoff delay in sequence and retries until the API
   * returns a non-ERR013 response or the backoff sequence is exhausted.
   *
   * @param string[] $batch
   *   The batch of normalized numbers to retry.
   * @param string $message
   *   Cleaned message text.
   * @param string $senderId
   *   Sender ID string.
   * @param bool $testMode
   *   Whether to send in test mode.
   *
   * @return array|null
   *   The last API response array, or NULL if all attempts failed at the
   *   network level.
   */
  private function retryWithBackoff(array $batch, string $message, string $senderId, bool $testMode): ?array {
    $mobile = implode(',', $batch);

    foreach (self::ERR013_BACKOFF as $delaySec) {
      $this->logger->warning(
        'kwtSMS: ERR013 (queue full). Retrying in @delay seconds.',
        ['@delay' => $delaySec],
      );
      sleep($delaySec);

      $result = $this->apiSend($mobile, $message, $senderId, $testMode);

      if ($result === NULL) {
        continue;
      }

      if (($result['code'] ?? '') !== 'ERR013') {
        return $result;
      }
    }

    $this->logger->error('kwtSMS: ERR013 backoff exhausted for batch of @count numbers.', ['@count' => count($batch)]);

    // Return the last result (which is still ERR013) so the caller can log it.
    return $this->apiSend($mobile, $message, $senderId, $testMode);
  }

  /**
   * Sends a raw HTTP POST request to a kwtSMS API endpoint.
   *
   * Uses Guzzle with a 30-second timeout and always sends Content-Type and
   * Accept headers set to application/json.
   *
   * @param string $endpoint
   *   The endpoint path relative to API_BASE (e.g. 'send/').
   * @param array $payload
   *   The request body as an associative array (will be JSON-encoded).
   *
   * @return array|null
   *   The decoded JSON response array, or NULL on network or decode failure.
   */
  private function apiCall(string $endpoint, array $payload): ?array {
    $url = self::API_BASE . ltrim($endpoint, '/');

    try {
      $response = $this->httpClient->request('POST', $url, [
        'headers' => [
          'Content-Type' => 'application/json',
          'Accept'       => 'application/json',
        ],
        'json'    => $payload,
        'timeout' => 30,
      ]);

      $body = (string) $response->getBody();
      $data = json_decode($body, TRUE);

      if (!is_array($data)) {
        $this->logger->error(
          'kwtSMS: non-JSON response from @endpoint: @body',
          ['@endpoint' => $endpoint, '@body' => substr($body, 0, 500)],
        );
        return NULL;
      }

      return $data;
    }
    catch (GuzzleException $e) {
      $this->logger->error(
        'kwtSMS: HTTP error calling @endpoint: @msg',
        ['@endpoint' => $endpoint, '@msg' => $e->getMessage()],
      );
      return NULL;
    }
  }

  /**
   * Calls the kwtSMS send/ endpoint with credentials from config and state.
   *
   * @param string $mobile
   *   Comma-separated normalized phone number string.
   * @param string $message
   *   Cleaned message body.
   * @param string $senderId
   *   The sender ID string.
   * @param bool $testMode
   *   Pass 1 for test mode, 0 for live.
   *
   * @return array|null
   *   The raw API response array, or NULL on failure.
   */
  private function apiSend(string $mobile, string $message, string $senderId, bool $testMode): ?array {
    $credentials = $this->getCredentials();

    $payload = [
      'username' => $credentials['username'],
      'password' => $credentials['password'],
      'sender'   => $senderId,
      'mobile'   => $mobile,
      'message'  => $message,
      'test'     => $testMode ? '1' : '0',
    ];

    return $this->apiCall('send/', $payload);
  }

  /**
   * Processes the raw API result from a send call.
   *
   * On success, updates the cached balance from balance-after and logs one
   * row per recipient via SmsLogger. On failure, logs an error row per
   * recipient.
   *
   * SmsLogger is retrieved via \Drupal::service() to avoid a circular
   * dependency (kwtsms.logger -> kwtsms.gateway would form a cycle).
   *
   * @param array|null $apiResult
   *   The raw API response, or NULL if the request failed entirely.
   * @param string[] $numbers
   *   The list of normalized numbers included in this send batch.
   * @param string $message
   *   The cleaned message text.
   * @param string $senderId
   *   The sender ID used.
   * @param bool $testMode
   *   Whether this was a test send.
   * @param string $eventType
   *   The event type label for the log row.
   * @param array $options
   *   Original options array (currently unused, available for future flags).
   *
   * @return array{success: bool, results: array, errors: array}
   *   Processed result with per-batch aggregates.
   */
  private function processApiResult(?array $apiResult, array $numbers, string $message, string $senderId, bool $testMode, string $eventType, array $options): array {
    /** @var \Drupal\kwtsms\Service\SmsLogger $smsLogger */
    $smsLogger = \Drupal::service('kwtsms.logger');

    if ($apiResult === NULL) {
      $errorMsg = 'API request failed (network or decode error).';
      foreach ($numbers as $number) {
        $smsLogger->logSend([
          'recipient'         => $number,
          'message'           => $message,
          'sender_id'         => $senderId,
          'status'            => 'failed',
          'error_code'        => 'NETWORK_ERROR',
          'error_description' => $errorMsg,
          'test_mode'         => (int) $testMode,
          'event_type'        => $eventType,
        ]);
      }
      return ['success' => FALSE, 'results' => [], 'errors' => [$errorMsg]];
    }

    $isOk = ($apiResult['result'] ?? '') === 'OK';

    if ($isOk) {
      // Update cached balance from the send response.
      if (isset($apiResult['balance-after'])) {
        $this->setCachedValue('balance', (int) $apiResult['balance-after']);
      }

      $msgId           = $apiResult['msg-id'] ?? NULL;
      $pointsCharged   = isset($apiResult['points-charged']) ? (int) $apiResult['points-charged'] : 0;
      $balanceAfter    = isset($apiResult['balance-after']) ? (int) $apiResult['balance-after'] : 0;
      $apiResponseJson = json_encode($apiResult);

      foreach ($numbers as $number) {
        $smsLogger->logSend([
          'recipient'      => $number,
          'message'        => $message,
          'sender_id'      => $senderId,
          'status'         => 'sent',
          'msg_id'         => $msgId,
          'api_response'   => $apiResponseJson,
          'points_charged' => $pointsCharged,
          'balance_after'  => $balanceAfter,
          'test_mode'      => (int) $testMode,
          'event_type'     => $eventType,
        ]);
      }

      return [
        'success' => TRUE,
        'results' => [
          [
            'numbers'        => count($numbers),
            'msg_id'         => $msgId,
            'points_charged' => $pointsCharged,
            'balance_after'  => $balanceAfter,
          ],
        ],
        'errors'  => [],
      ];
    }

    // API returned ERROR.
    $errorCode       = $apiResult['code'] ?? 'UNKNOWN';
    $errorDesc       = $apiResult['description'] ?? 'Unknown API error.';
    $apiResponseJson = json_encode($apiResult);

    foreach ($numbers as $number) {
      $smsLogger->logSend([
        'recipient'         => $number,
        'message'           => $message,
        'sender_id'         => $senderId,
        'status'            => 'failed',
        'error_code'        => $errorCode,
        'error_description' => $errorDesc,
        'api_response'      => $apiResponseJson,
        'test_mode'         => (int) $testMode,
        'event_type'        => $eventType,
      ]);
    }

    return [
      'success' => FALSE,
      'results' => [],
      'errors'  => [sprintf('%s: %s', $errorCode, $errorDesc)],
    ];
  }

  /**
   * Returns the stored API credentials.
   *
   * @return array{username: string, password: string}
   *   Associative array with 'username' and 'password' keys.
   */
  private function getCredentials(): array {
    $config = $this->configFactory->get('kwtsms.settings');
    return [
      'username' => (string) ($config->get('api_username') ?? ''),
      'password' => (string) ($this->state->get('kwtsms.api_password', '') ?? ''),
    ];
  }

  /**
   * Returns the cached balance, or NULL if not cached.
   *
   * @return int|null
   *   The cached balance integer, or NULL.
   */
  private function getCachedBalance(): ?int {
    $value = $this->getCachedValue('balance');
    return $value !== NULL ? (int) $value : NULL;
  }

  /**
   * Returns the cached coverage data, or NULL if not cached.
   *
   * @return array|null
   *   The decoded coverage response array, or NULL if not cached.
   */
  private function getCachedCoverage(): ?array {
    $value = $this->getCachedValue('coverage');
    return is_array($value) ? $value : NULL;
  }

  /**
   * Determines whether a normalized phone number is covered by the API.
   *
   * Checks the 'prefixes' key of the cached coverage response. If the
   * coverage data does not contain a recognized 'prefixes' array the method
   * returns TRUE (permissive: let the API reject it if needed).
   *
   * @param string $number
   *   A normalized phone number (digits only, no plus sign).
   * @param array $coverage
   *   The decoded coverage API response.
   *
   * @return bool
   *   TRUE when the number's prefix is found in coverage, or when coverage
   *   data is not in an expected format.
   */
  private function isCovered(string $number, array $coverage): bool {
    // Coverage is stored as a flat array of prefix strings: ['965', '966', ...].
    if (empty($coverage)) {
      return TRUE;
    }

    foreach ($coverage as $prefix) {
      if (str_starts_with($number, (string) $prefix)) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
