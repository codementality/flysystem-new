<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Kernel\Core;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The directory replay against a local-driver binding.
 *
 * Same suite, one word: the driver is 'local'; the binding roots at a
 * temporary directory; directory-mode legs run.
 */
#[Group('flysystem')]
#[RunTestsInSeparateProcesses]
final class DirectoryLocalTest extends DirectoryReplay {

  /**
   * {@inheritdoc}
   */
  protected function replayDriver(): string {
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
