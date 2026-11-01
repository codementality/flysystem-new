<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Functional\Plugin;

use Drupal\flysystem\Plugin\FlysystemAdapterInterface;
use Drupal\flysystem\Plugin\FlysystemAdapterManager;
use Drupal\flysystem\Plugin\LocalStorageInterface;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Functional test: real container discovery of the local plugin.
 *
 * Proves the whole chain the unit tests cannot: the #[FlysystemAdapter]
 * attribute is discovered by the manager service through the normal
 * Drupal plugin system, the container factory hands the plugin the app
 * root, and an operator built from it performs a real round-trip.
 */
#[Group('flysystem')]
#[RunTestsInSeparateProcesses]
final class LocalAdapterDiscoveryTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  /**
   * Modules to install on the fresh site.
   *
   * @var string[]
   */
  protected static $modules = ['flysystem'];

  /**
   * Verifies the manager service discovers the local plugin by id.
   */
  public function testManagerDiscoversLocalPlugin(): void {
    $manager = \Drupal::service('plugin.manager.flysystem_adapter');
    self::assertInstanceOf(FlysystemAdapterManager::class, $manager);
    $definitions = $manager->getDefinitions();
    self::assertArrayHasKey('local', $definitions, 'Enabling the flysystem module must make the local adapter discoverable by id.');
  }

  /**
   * Verifies instantiation, feature declaration, and a real round-trip.
   */
  public function testLocalPluginInstantiatesAndRoundTrips(): void {
    $manager = \Drupal::service('plugin.manager.flysystem_adapter');
    $plugin = $manager->createInstance('local', ['root' => 'm109-discovery']);
    self::assertInstanceOf(FlysystemAdapterInterface::class, $plugin);
    self::assertTrue($plugin instanceof LocalStorageInterface);
    self::assertTrue($plugin->isAvailable());

    // Round-trip in the OS temp directory via an absolute root — the
    // plugin passes absolute roots verbatim; nothing is written into the
    // shared checkout. Anchoring of RELATIVE roots is unit-tested in
    // LocalAdapterPluginTest with a per-process app root.
    $tempRoot = sys_get_temp_dir() . '/flysystem-m109-functional-' . getmypid();
    $plugin = $manager->createInstance('local', ['root' => $tempRoot]);
    $operator = $plugin->buildFilesystem();
    $operator->write('probe.txt', 'discovered and working');
    self::assertSame('discovered and working', $operator->read('probe.txt'));
    self::assertFileExists($tempRoot . '/probe.txt');
    $operator->delete('probe.txt');
    @rmdir($tempRoot);
  }

}
