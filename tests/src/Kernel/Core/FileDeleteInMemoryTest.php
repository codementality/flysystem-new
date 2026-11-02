<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Kernel\Core;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The delete replay against the in-memory driver.
 *
 * Same suite, one word: the driver is 'in_memory'. The two symlink
 * legs are skipped by name: their fixture is a real filesystem
 * symlink, which an in-memory binding has no disk surface to host.
 */
#[Group('flysystem')]
#[RunTestsInSeparateProcesses]
final class FileDeleteInMemoryTest extends FileDeleteReplay {

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
