<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Kernel\Adapter;

use Drupal\flysystem\Adapter\FlysystemAdapterInterface;
use Drupal\flysystem\Adapter\FlysystemAdapterManager;
use Drupal\flysystem_adapter_test\Plugin\FlysystemAdapter\MemoryBackedTestAdapter;
use Drupal\KernelTests\KernelTestBase;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves the contributed adapter rail end to end through real wiring.
 *
 * A hidden testing module ships an adapter the same way any third party
 * would: one class in its Plugin/FlysystemAdapter directory carrying the
 * adapter attribute. This test boots the real container with that module
 * enabled and asserts the whole production path consumes it — the
 * plugin manager discovers it through standard attribute discovery, the
 * info hook can alter it, discovery is cached in the discovery cache
 * bin, the factory instantiates it through the manager (never with new
 * in module code), and a scheme bound to it gets a working operator —
 * with zero changes to production code anywhere. If this passes, the
 * extension story is a property of the plugin API, not a promise in
 * documentation.
 */
#[Group('flysystem')]
#[RunTestsInSeparateProcesses]
final class ContribStyleAdapterDiscoveryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['flysystem', 'flysystem_adapter_test'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // A scheme bound to the contributed driver, settings-carried like any.
    $this->setSetting('flysystem', [
      'ttest' => ['driver' => 'test_adapter'],
    ]);
  }

  /**
   * The contributed plugin is discovered, altered, and class-known.
   */
  public function testContributedAdapterIsDiscoveredThroughThePluginApi(): void {
    /** @var \Drupal\flysystem\Adapter\FlysystemAdapterManager $manager */
    $manager = $this->container->get('flysystem.adapter_manager');
    $this->assertInstanceOf(FlysystemAdapterManager::class, $manager);

    $definitions = $manager->getDefinitions();
    $this->assertArrayHasKey('test_adapter', $definitions, 'A class shipped by another module in Plugin/FlysystemAdapter becomes a discoverable adapter through the plugin API.');
    $this->assertArrayHasKey('local', $definitions, 'The first-party local adapter rides the identical discovery rail.');
    $this->assertArrayNotHasKey('in_memory', $definitions, 'An adapter shipped by a module that is not enabled is simply absent — module presence, not a branch in code, decides what exists.');
    $this->assertSame(
      MemoryBackedTestAdapter::class,
      $definitions['test_adapter']['class'],
      'The definition names the contributed class.'
    );
    $this->assertSame(
      'Altered through the info hook',
      (string) $definitions['test_adapter']['label'],
      'The standard info hook alters discovered definitions before they are handed out.'
    );
  }

  /**
   * Discovery results are cached, so repeat lookups cost no scanning.
   */
  public function testDiscoveryIsCachedInTheDiscoveryBin(): void {
    /** @var \Drupal\flysystem\Adapter\FlysystemAdapterManager $manager */
    $manager = $this->container->get('flysystem.adapter_manager');
    $manager->getDefinitions();

    $cache = $this->container->get('cache.discovery')
      ->get('flysystem_adapter_plugins');
    $this->assertNotFalse(
      $cache,
      'The manager caches its definitions under the discovery cache bin — a cacheable extension rail is the point of using the plugin API.'
    );
  }

  /**
   * The manager instantiates the contributed adapter on demand.
   */
  public function testManagerCreatesTheContributedInstance(): void {
    /** @var \Drupal\flysystem\Adapter\FlysystemAdapterManager $manager */
    $manager = $this->container->get('flysystem.adapter_manager');
    $adapter = $manager->createInstance('test_adapter', ['marker' => 'x']);
    $this->assertInstanceOf(FlysystemAdapterInterface::class, $adapter);
    $this->assertInstanceOf(
      MemoryBackedTestAdapter::class,
      $adapter,
      'The factory builds the plugin — production code never calls new on an adapter class.'
    );
    $this->assertSame('test_adapter', $adapter->getPluginId());
  }

  /**
   * The factory builds a working operator for the contributed driver.
   */
  public function testFactoryBuildsOperatorForContributedDriver(): void {
    /** @var \Drupal\flysystem\Factory\OperatorFactory $factory */
    $factory = $this->container->get('flysystem.operator_factory');

    $operator = $factory->operatorFor('ttest');
    $this->assertInstanceOf(
      FilesystemOperator::class,
      $operator,
      'A scheme bound to a contributed driver resolves to a working operator with no production change.'
    );

    $operator->write('proof.txt', 'contributed');
    $this->assertSame(
      'contributed',
      $operator->read('proof.txt'),
      'Bytes round-trip through the contributed backend.'
    );

    $this->assertSame(
      $operator,
      $factory->operatorFor('ttest'),
      'The operator is memoized for the request, as with first-party adapters.'
    );
  }

}
