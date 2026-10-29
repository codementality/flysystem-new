<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Functional\Plugin;

use Drupal\flysystem\Plugin\FlysystemAdapterInterface;
use Drupal\flysystem\Plugin\LocalStorageInterface;
use Drupal\Tests\BrowserTestBase;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Functional proof: real discovery finds both shipped adapters.
 *
 * On a fresh install, the container-built plugin manager discovers the
 * local and memory plugins through their attributes alone — the
 * pluggability claim in end-to-end form: two backends, one new class
 * each, no base-module branching. The memory backend round-trips through
 * the same interface and stores nothing on disk.
 */
#[Group('flysystem')]
#[RunTestsInSeparateProcesses]
final class MemoryAdapterDiscoveryTest extends BrowserTestBase {

  /**
   * The theme under test.
   *
   * @var string
   */
  protected $defaultTheme = 'stark';

  /**
   * Modules to install on the fresh site.
   *
   * @var string[]
   */
  protected static $modules = ['flysystem', 'flysystem_memory'];

  /**
   * Verifies the manager service discovers both shipped adapter plugins.
   */
  public function testDiscoveryFindsBothShippedAdapters(): void {
    $definitions = \Drupal::service('plugin.manager.flysystem_adapter')->getDefinitions();
    $ids = array_keys($definitions);
    sort($ids);
    self::assertSame(['local', 'memory'], $ids, 'Both shipped backends arrive through attribute discovery, nothing else.');
  }

  /**
   * Verifies the memory plugin instantiates and round-trips via the container.
   */
  public function testMemoryInstantiatesAndRoundTrips(): void {
    $plugin = \Drupal::service('plugin.manager.flysystem_adapter')->createInstance('memory', []);
    self::assertInstanceOf(FlysystemAdapterInterface::class, $plugin);
    self::assertFalse($plugin instanceof LocalStorageInterface);
    self::assertTrue($plugin->isAvailable());
    $operator = $plugin->buildFilesystem(['root' => $this->tempRoot()]);
    self::assertInstanceOf(FilesystemOperator::class, $operator);
    $operator->write('ram/only.txt', 'never on disk');
    self::assertSame('never on disk', $operator->read('ram/only.txt'));
    self::assertDirectoryDoesNotExist($this->tempRoot(), 'Memory ignores the configured root and creates no filesystem paths.');
  }

  /**
   * A path that must not exist after memory-only use.
   */
  private function tempRoot(): string {
    return \Drupal::root() . '/m110-never-' . getmypid();
  }

}
