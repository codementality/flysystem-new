<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Kernel\Adapter;

use Drupal\flysystem\Adapter\FlysystemAdapterManager;
use Drupal\KernelTests\KernelTestBase;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The testing-only in-memory driver registers through the same rail.
 *
 * When the hidden flysystem_memory submodule is present (test
 * dependency), its tagged service becomes the 'in_memory' driver and
 * the factory builds working operators for schemes bound to it — with
 * no disk touched, which is the point of a backend whose only role is
 * proving adapter-agnosticism. When the submodule is absent, the
 * driver simply does not exist and nothing references it.
 */
#[Group('flysystem')]
#[RunTestsInSeparateProcesses]
final class InMemoryDriverRegistrationTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['flysystem', 'flysystem_memory'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // A scheme bound to the testing-only driver.
    $this->setSetting('flysystem', [
      'tmem' => ['driver' => 'in_memory'],
    ]);
  }

  /**
   * Both first-party drivers are discovered, and memory works.
   */
  public function testInMemoryDriverRegisteredAndFunctional(): void {
    /** @var \Drupal\flysystem\Adapter\FlysystemAdapterManager $manager */
    $manager = $this->container->get('flysystem.adapter_manager');
    self::assertInstanceOf(FlysystemAdapterManager::class, $manager);
    $definitions = $manager->getDefinitions();
    self::assertArrayHasKey('in_memory', $definitions, 'The hidden submodule contributes its driver through the same tag rail as the shipped one.');
    self::assertArrayHasKey('local', $definitions);

    /** @var \Drupal\flysystem\Factory\OperatorFactory $factory */
    $factory = $this->container->get('flysystem.operator_factory');
    $operator = $factory->operatorFor('tmem');
    self::assertInstanceOf(FilesystemOperator::class, $operator);
    $operator->write('volatile.txt', 'ram only');
    self::assertSame('ram only', $operator->read('volatile.txt'));
  }

}
