<?php

declare(strict_types=1);

namespace Drupal\kwtsms\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Utility\Token;

/**
 * Renders kwtSMS templates by loading a config entity and replacing tokens.
 *
 * Language resolution order:
 *   1. Forced language from kwtsms.settings sms_language (en or ar).
 *   2. User preferred language (if $data['user'] is a UserInterface).
 *   3. Site default language.
 */
class TemplateRenderer {

  /**
   * Constructs a TemplateRenderer instance.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Utility\Token $token
   *   The token service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Token $token,
    protected ConfigFactoryInterface $configFactory,
    protected LanguageManagerInterface $languageManager,
  ) {}

  /**
   * Renders an SMS template by ID.
   *
   * Loads the template config entity, resolves the output language, applies
   * simple extraTokens replacements, then runs Drupal token replacement.
   *
   * @param string $templateId
   *   The kwtsms_template config entity ID.
   * @param array $data
   *   Token data array passed to Drupal's token service (e.g. ['user' => $account]).
   * @param array $extraTokens
   *   Simple search-replace pairs applied before Drupal tokens, keyed by the
   *   full token string (e.g. ['[kwtsms:otp-code]' => '123456']).
   * @param string|null $langcode
   *   Optional language override. When provided it bypasses config/user
   *   resolution and forces a specific language.
   *
   * @return string|null
   *   The fully rendered message, or NULL if the template does not exist.
   */
  public function render(string $templateId, array $data = [], array $extraTokens = [], ?string $langcode = NULL): ?string {
    $storage = $this->entityTypeManager->getStorage('kwtsms_template');
    $template = $storage->load($templateId);

    if ($template === NULL) {
      return NULL;
    }

    $langcode = $langcode ?? $this->resolveLanguage($data);

    $body = $template->getBody($langcode);

    if (!empty($extraTokens)) {
      $body = str_replace(array_keys($extraTokens), array_values($extraTokens), $body);
    }

    $tokenOptions = [
      'langcode' => $langcode,
      'clear' => TRUE,
    ];

    $body = $this->token->replace($body, $data, $tokenOptions);

    return $body;
  }

  /**
   * Resolves the SMS output language from config and context.
   *
   * @param array $data
   *   Token data that may contain a 'user' key with a UserInterface object.
   *
   * @return string
   *   A language code: 'en', 'ar', or the site default langcode.
   */
  private function resolveLanguage(array $data): string {
    $smsLanguage = $this->configFactory->get('kwtsms.settings')->get('sms_language') ?? 'auto';

    if ($smsLanguage === 'en' || $smsLanguage === 'ar') {
      return $smsLanguage;
    }

    if (isset($data['user']) && method_exists($data['user'], 'getPreferredLangcode')) {
      $preferred = $data['user']->getPreferredLangcode();
      if ($preferred !== '') {
        return $preferred;
      }
    }

    return $this->languageManager->getDefaultLanguage()->getId();
  }

}
