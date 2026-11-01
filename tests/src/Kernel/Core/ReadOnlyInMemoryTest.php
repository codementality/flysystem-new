<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Kernel\Core;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The read-only replay against the in-memory driver.
 *
 * Same suite, one word: the driver is 'in_memory'. The single replayed
 * test seeds its readable fixture by writing a real file onto the
 * disk the wrapper maps onto its scheme; an in-memory binding has no
 * such disk surface, and seeding through the operator is not possible
 * before the scheme registration has bound one — so that test is
 * skipped by name here.
 */
#[Group('flysystem')]
#[RunTestsInSeparateProcesses]
final class ReadOnlyInMemoryTest extends ReadOnlyReplay {

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

  /**
   * {@inheritdoc}
   */
  public function testReadOnlyBehavior(): void {
    $this->markTestSkipped('Fixture requires a real on-disk seed the read-only wrapper maps onto its scheme; an in-memory binding has no disk surface to seed, and no operator exists before registration to seed through.');
  }

}
