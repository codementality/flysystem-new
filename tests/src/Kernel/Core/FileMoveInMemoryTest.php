<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Kernel\Core;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The move replay against the in-memory driver.
 *
 * Same suite, one word: the driver is 'in_memory'. Mode-policy legs
 * are local-storage declarations and are not claimed here.
 */
#[Group('flysystem')]
#[RunTestsInSeparateProcesses]
final class FileMoveInMemoryTest extends FileMoveReplay {

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
