<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Kernel\Adapter;

use Drupal\flysystem\Adapter\FlysystemAdapterManager;
use Drupal\flysystem_memory\Plugin\FlysystemAdapter\InMemory;
use Drupal\KernelTests\KernelTestBase;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The testing-only in-memory adapter ships as a plugin like any other.
 *
 * When the hidden flysystem_memory submodule is present (test
 * dependency), its attributed class is discovered as the 'in_memory'
 * adapter and the factory builds working operators for schemes bound to
 * it — with no disk touched, which is the point of a backend whose only
 * role is proving adapter-agnosticism. When the submodule is absent,
 * discovery never sees it and the adapter simply does not exist.
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
    // A scheme bound to the testing-only adapter.
    $this->setSetting('flysystem', [
      'tmem' => ['driver' => 'in_memory'],
    ]);
  }

  /**
   * Both first-party adapters are discovered, and memory works.
   */
  public function testInMemoryDriverRegisteredAndFunctional(): void {
    /** @var \Drupal\flysystem\Adapter\FlysystemAdapterManager $manager */
    $manager = $this->container->get('flysystem.adapter_manager');
    $this->assertInstanceOf(FlysystemAdapterManager::class, $manager);
    $definitions = $manager->getDefinitions();
    $this->assertArrayHasKey('in_memory', $definitions, 'The hidden submodule contributes its adapter through the identical plugin discovery rail as the shipped one.');
    $this->assertArrayHasKey('local', $definitions);
    $this->assertInstanceOf(
      InMemory::class,
      $manager->createInstance('in_memory', []),
      'The manager instantiates the shipped class through the plugin factory.'
    );

    /** @var \Drupal\flysystem\Factory\OperatorFactory $factory */
    $factory = $this->container->get('flysystem.operator_factory');
    $operator = $factory->operatorFor('tmem');
    $this->assertInstanceOf(FilesystemOperator::class, $operator);
    $operator->write('volatile.txt', 'ram only');
    $this->assertSame('ram only', $operator->read('volatile.txt'));
  }

}
