<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Kernel\Core;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The read-only replay against a local-driver binding.
 *
 * Same suite, one word: the driver is 'local'; the binding roots at a
 * temporary directory, and the readable fixture is seeded under that
 * root's real path — the disk surface the read-only posture assumes.
 */
#[Group('flysystem')]
#[RunTestsInSeparateProcesses]
final class ReadOnlyLocalTest extends ReadOnlyLocalStorageReplay {

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
