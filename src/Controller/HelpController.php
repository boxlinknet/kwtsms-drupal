<?php

declare(strict_types=1);

namespace Drupal\kwtsms\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Renders the kwtSMS Help page.
 */
class HelpController extends ControllerBase {

  /**
   * Renders the help page.
   *
   * @return array
   *   A render array using the kwtsms_help theme hook.
   */
  public function page(): array {
    return [
      '#theme' => 'kwtsms_help',
    ];
  }

}
