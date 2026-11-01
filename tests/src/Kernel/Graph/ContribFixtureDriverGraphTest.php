<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Kernel\Graph;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\flysystem\Traits\AdapterAgnosticGraphAssertionsTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The filesystem service graph over a contributed-adapter fixture.
 *
 * One of three host tests running the identical shared assertion base;
 * the contributed plugin — shipped by a hidden testing module with a
 * single attributed class — passes the same legs as the first-party
 * backends. The fixture adapter has no local feature, so the interior
 * '..' leg expects strict rejection.
 */
#[Group('flysystem')]
#[RunTestsInSeparateProcesses]
final class ContribFixtureDriverGraphTest extends KernelTestBase {

  use AdapterAgnosticGraphAssertionsTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['flysystem', 'flysystem_adapter_test'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->swapScheme = 'graph-swap';
    $this->setSetting('flysystem', [
      'graph-swap' => ['driver' => 'test_adapter'],
      'graph-ghost' => ['driver' => 'ghost_driver'],
    ]);
  }

  /**
   * The shared legs, strict posture.
   */
  public function testGraphOnContributedAdapter(): void {
    $this->runGraphAssertions(FALSE);
  }

}
