<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Kernel\Core;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The file-system replay against a local-driver binding.
 *
 * Same suite, one word: the driver is 'local'. Because the binding
 * names an on-disk location, message expectations can embed the real
 * path of the sample file, exactly as core's body does.
 */
#[Group('flysystem')]
#[RunTestsInSeparateProcesses]
final class FileSystemLocalTest extends FileSystemReplay {

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

  /**
   * {@inheritdoc}
   */
  protected function replaySampleRealPath(string $path): ?string {
    return $this->realPath($path);
  }

}
