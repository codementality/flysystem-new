<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Kernel\Core;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The save-data replay against a local-adapter binding.
 *
 * Same suite, one word: the adapter is 'local'; the binding roots at a
 * temporary directory; local-storage legs (file modes) run.
 */
#[Group('flysystem')]
#[RunTestsInSeparateProcesses]
final class FileSaveDataLocalTest extends FileSaveDataLocalStorageReplay {

  /**
   * {@inheritdoc}
   */
  protected function replayAdapter(): string {
    return 'local';
  }

  /**
   * {@inheritdoc}
   */
  protected function replayIsLocalStorage(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  protected function replayConfig(): array {
    return ['root' => $this->replayRoot];
  }

}
