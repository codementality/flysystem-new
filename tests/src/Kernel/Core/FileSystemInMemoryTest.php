<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Kernel\Core;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The file-system replay against the in-memory driver.
 *
 * Same suite, one word: the driver is 'in_memory'. Mode-policy legs
 * are local-storage declarations and are not claimed here; exception
 * messages are asserted by class and URI prefix because the realpath
 * of a non-local binding is the wrapper's answer, not a path on disk.
 */
#[Group('flysystem')]
#[RunTestsInSeparateProcesses]
final class FileSystemInMemoryTest extends FileSystemReplay {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'flysystem', 'flysystem_memory'];

  /**
   * {@inheritdoc}
   */
  protected function replayDriver(): string {
    return 'in_memory';
  }

  /**
   * {@inheritdoc}
   */
  protected function replayIsLocalStorage(): bool {
    return FALSE;
  }

}
