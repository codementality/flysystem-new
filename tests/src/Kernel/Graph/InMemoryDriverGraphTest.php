<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Kernel\Graph;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\flysystem\Traits\AdapterAgnosticGraphAssertionsTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The filesystem service graph over the in-memory adapter.
 *
 * One of three host tests running the identical shared assertion base;
 * the only differences from the local host are the enabled modules and
 * the driver word. No local-storage feature is declared, so the
 * interior '..' leg expects strict rejection.
 */
#[Group('flysystem')]
#[RunTestsInSeparateProcesses]
final class InMemoryDriverGraphTest extends KernelTestBase {

  use AdapterAgnosticGraphAssertionsTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['flysystem', 'flysystem_memory'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->swapScheme = 'graph_swap';
    $this->setSetting('flysystem', [
      'graph_swap' => ['driver' => 'in_memory'],
      'graph_ghost' => ['driver' => 'ghost_driver'],
    ]);
  }

  /**
   * The shared legs, strict posture.
   */
  public function testGraphOnInMemoryAdapter(): void {
    $this->runGraphAssertions(FALSE);
  }

}
