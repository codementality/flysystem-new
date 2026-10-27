<?php

declare(strict_types=1);

namespace Drupal\flysystem\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\flysystem\Verification\StatusReporter;

/**
 * Hook implementations for the Flysystem module.
 */
final class FlysystemHooks {

  /**
   * Constructs the hook class.
   *
   * @param \Drupal\flysystem\Verification\StatusReporter $statusReporter
   *   Builds the status report section from the verification surface.
   */
  public function __construct(
    protected readonly StatusReporter $statusReporter,
  ) {
  }

  /**
   * Implements hook_runtime_requirements().
   *
   * The status report page is the always-present surface where a site
   * operator learns what is wrong with the module's configuration —
   * including configuration that lives entirely in settings.php and has
   * no administration form. The section content comes from the status
   * reporter, which asks the verification surface; verification logs
   * every finding as it checks, so log records exist independently of
   * anyone viewing the page.
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    return ['flysystem' => $this->statusReporter->buildEntry()];
  }

}
