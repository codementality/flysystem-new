<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Kernel\Core;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The delete replay against a local-adapter binding.
 *
 * Same suite, one word: the adapter is 'local'; the binding roots at a
 * temporary directory; the symlink legs build their fixtures under
 * that root's real path.
 */
#[Group('flysystem')]
#[RunTestsInSeparateProcesses]
final class FileDeleteLocalTest extends FileDeleteLocalStorageReplay {

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
