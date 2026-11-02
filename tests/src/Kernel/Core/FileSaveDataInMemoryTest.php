<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Kernel\Core;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The save-data replay against the in-memory adapter.
 *
 * Same suite, one word: the adapter is 'in_memory'. Mode-policy legs
 * are local-storage declarations and are not claimed here.
 */
#[Group('flysystem')]
#[RunTestsInSeparateProcesses]
final class FileSaveDataInMemoryTest extends FileSaveDataReplay {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'flysystem', 'flysystem_memory'];

  /**
   * {@inheritdoc}
   */
  protected function replayAdapter(): string {
    return 'in_memory';
  }

  /**
   * {@inheritdoc}
   */
  protected function replayIsLocalStorage(): bool {
    return FALSE;
  }

}
