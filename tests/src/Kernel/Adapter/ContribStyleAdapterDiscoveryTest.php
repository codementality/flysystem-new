<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Kernel\Adapter;

use Drupal\flysystem\Adapter\FlysystemAdapterManager;
use Drupal\KernelTests\KernelTestBase;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\Group;

/**
 * Proves the contrib adapter rail end to end through real wiring.
 *
 * A hidden testing module registers an adapter the same way any third
 * party would: a class implementing the contract plus four lines of
 * tagged service YAML. This test boots the real container with that
 * module enabled and asserts the whole production path consumes it —
 * the manager discovers the driver, and the operator factory builds a
 * working operator for a scheme bound to it — with zero changes to
 * production code anywhere. If this passes, the extension story is a
 * property of the wiring, not a promise in documentation.
 */
#[Group('flysystem')]
final class ContribStyleAdapterDiscoveryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['flysystem', 'flysystem_adapter_test'];

  /**
   * A scheme bound to the contributed driver, settings-carried like any.
   *
   * @var array
   */
  protected $settings = [
    'flysystem' => [
      'ttest' => ['driver' => 'test_adapter'],
    ],
  ];

  /**
   * The contributed driver appears in the manager's definitions.
   */
  public function testContributedDriverIsDiscovered(): void {
    /** @var \Drupal\flysystem\Adapter\FlysystemAdapterManager $manager */
    $manager = $this->container->get('flysystem.adapter_manager');
    self::assertInstanceOf(FlysystemAdapterManager::class, $manager);

    $definitions = $manager->getDefinitions();
    self::assertArrayHasKey('test_adapter', $definitions, 'A tagged service from another module becomes a driver definition through the same rail the first-party adapters ride.');
    self::assertArrayHasKey('local', $definitions, 'The first-party local adapter is registered through the identical tag mechanism.');
    self::assertSame(
      'flysystem_adapter_test.memory_backed',
      $definitions['test_adapter']['service'],
      'The definition points at the contributed service id.'
    );
    self::assertSame(
      'Memory-backed test adapter',
      $definitions['test_adapter']['label'],
      'The tag carries the human label through to the definition.'
    );
  }

  /**
   * The factory builds a working operator for the contributed driver.
   */
  public function testFactoryBuildsOperatorForContributedDriver(): void {
    /** @var \Drupal\flysystem\Factory\OperatorFactory $factory */
    $factory = $this->container->get('flysystem.operator_factory');

    $operator = $factory->operatorFor('ttest');
    self::assertInstanceOf(
      FilesystemOperator::class,
      $operator,
      'A scheme bound to a contributed driver resolves to a working operator with no production change.'
    );

    $operator->write('proof.txt', 'contributed');
    self::assertSame(
      'contributed',
      $operator->read('proof.txt'),
      'Bytes round-trip through the contributed backend.'
    );

    self::assertSame(
      $operator,
      $factory->operatorFor('ttest'),
      'The operator is memoized for the request, as with first-party adapters.'
    );
  }

}
