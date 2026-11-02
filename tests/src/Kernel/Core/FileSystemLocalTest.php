<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Kernel\Core;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The file-system replay against a local-adapter binding.
 *
 * Same suite, one word: the adapter is 'local'. Because the binding
 * names an on-disk location, message expectations can embed the real
 * path of the sample file, exactly as core's body does.
 */
#[Group('flysystem')]
#[RunTestsInSeparateProcesses]
final class FileSystemLocalTest extends FileSystemLocalStorageReplay {

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
