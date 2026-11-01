<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Kernel\Graph;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\flysystem\Traits\AdapterAgnosticGraphAssertionsTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The filesystem service graph over the local-storage adapter.
 *
 * One of three host tests running the identical shared assertion base
 * (AdapterAgnosticGraphAssertionsTrait); between them differ exactly
 * two things: the enabled modules and the driver word in the settings
 * binding. Local storage declares the local feature, so the interior
 * '..' leg expects library-default resolution inside the bound root.
 */
#[Group('flysystem')]
#[RunTestsInSeparateProcesses]
final class LocalDriverGraphTest extends KernelTestBase {

  use AdapterAgnosticGraphAssertionsTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['flysystem'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->swapScheme = 'graph_swap';
    $this->setSetting('flysystem', [
      'graph_swap' => ['driver' => 'local', 'config' => ['root' => 'graph-local']],
      'graph_ghost' => ['driver' => 'ghost_driver'],
    ]);
  }

  /**
   * The shared legs, local posture.
   */
  public function testGraphOnLocalAdapter(): void {
    $this->runGraphAssertions(TRUE);
  }

}
