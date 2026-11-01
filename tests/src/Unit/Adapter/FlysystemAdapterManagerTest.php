<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Unit\Adapter;

use Drupal\Core\DependencyInjection\Container;
use Drupal\flysystem\Adapter\FlysystemAdapterInterface;
use Drupal\flysystem\Adapter\FlysystemAdapterManager;
use Drupal\flysystem\Exception\FlysystemAdapterServiceInvalidException;
use Drupal\flysystem\Exception\FlysystemPluginNotFoundException;
use Drupal\Tests\UnitTestCase;
use League\Flysystem\FilesystemAdapter;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the adapter manager's runtime contract over tagged services.
 *
 * The manager answers two questions: which drivers exist (definitions,
 * alterable through the standard info-alter hook) and, given a driver,
 * the adapter service itself. Instances are not built by the manager —
 * each adapter is a container service, constructed by Symfony with its
 * own dependencies, which is what makes swapping a backend in any
 * environment a one-word change in a binding definition.
 */
#[Group('flysystem')]
#[CoversNothing]
final class FlysystemAdapterManagerTest extends UnitTestCase {

  /**
   * Builds a manager whose alter hook passes definitions through.
   *
   * @param array $definitions
   *   Driver-keyed definitions as the compiler pass would hand them over.
   * @param \Drupal\Core\DependencyInjection\Container $container
   *   The container stub holding the adapter services.
   * @param object|null $handler
   *   (optional) Module handler double; default passes alter through.
   *
   * @return \Drupal\flysystem\Adapter\FlysystemAdapterManager
   *   The manager under test.
   */
  private function manager(
    array $definitions,
    Container $container,
    ?object $handler = NULL,
  ): FlysystemAdapterManager {
    $handler = $handler ?? $this->createMock(
      '\Drupal\Core\Extension\ModuleHandlerInterface'
    );
    return new FlysystemAdapterManager($definitions, $container, $handler);
  }

  /**
   * A minimal adapter service for manager plumbing.
   */
  private function makeAdapter(): FlysystemAdapterInterface {
    return new class() implements FlysystemAdapterInterface {

      /**
       * Produces nothing real; the manager never asks the backend here.
       */
      public function adapter(array $config): FilesystemAdapter {
        throw new \LogicException('Not needed for manager plumbing.');
      }

      /**
       * This plumbing stand-in is always usable.
       */
      public function isAvailable(): bool {
        return TRUE;
      }

    };
  }

  /**
   * Verifies definitions arrive keyed by driver and alter fires once.
   */
  public function testDefinitionsAndAlter(): void {
    $adapter = $this->makeAdapter();
    $container = new Container();
    $container->set('flysystem.adapter.local', $adapter);

    $handler = $this->createMock(
      '\Drupal\Core\Extension\ModuleHandlerInterface'
    );
    $handler->expects(self::once())->method('alter')
      ->with('flysystem_adapter_info');

    $manager = $this->manager([
      'local' => [
        'driver' => 'local',
        'label' => 'Local files',
        'service' => 'flysystem.adapter.local',
      ],
    ], $container, $handler);

    $definitions = $manager->getDefinitions();
    self::assertSame(
      ['local'],
      array_keys($definitions),
      'The manager presents the definitions the build phase collected, keyed by driver, after the alter hook has run.'
    );
  }

  /**
   * Verifies createInstance hands out the container service itself.
   */
  public function testCreateInstanceReturnsTheContainerService(): void {
    $adapter = $this->makeAdapter();
    $container = new Container();
    $container->set('flysystem.adapter.local', $adapter);
    $manager = $this->manager([
      'local' => [
        'driver' => 'local',
        'label' => 'Local files',
        'service' => 'flysystem.adapter.local',
      ],
    ], $container);

    self::assertSame(
      $adapter,
      $manager->createInstance('local'),
      'The adapter used is the service Symfony built — injection lives in the container, not in the manager.'
    );
  }

  /**
   * Verifies an unknown driver raises the typed not-found error.
   */
  public function testUnknownDriverThrowsTypedException(): void {
    $manager = $this->manager([], new Container());

    $this->expectException(FlysystemPluginNotFoundException::class);
    $this->expectExceptionMessageMatches('/nosuchdriver/');
    $manager->createInstance('nosuchdriver');
  }

  /**
   * Verifies a tagged service failing the contract is a typed error.
   */
  public function testServiceNotImplementingContractThrows(): void {
    $container = new Container();
    $container->set('flysystem.adapter.bad', new \stdClass());
    $manager = $this->manager([
      'bad' => [
        'driver' => 'bad',
        'label' => 'Bad',
        'service' => 'flysystem.adapter.bad',
      ],
    ], $container);

    $this->expectException(FlysystemAdapterServiceInvalidException::class);
    $manager->createInstance('bad');
  }

  /**
   * Verifies a definition naming a missing service is the typed error.
   */
  public function testMissingServiceThrowsTypedException(): void {
    $manager = $this->manager([
      'ghost' => [
        'driver' => 'ghost',
        'label' => 'Ghost',
        'service' => 'flysystem.adapter.ghost',
      ],
    ], new Container());

    $this->expectException(FlysystemAdapterServiceInvalidException::class);
    $manager->createInstance('ghost');
  }

}
