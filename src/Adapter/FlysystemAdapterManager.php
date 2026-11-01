<?php

declare(strict_types=1);

namespace Drupal\flysystem\Adapter;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\flysystem\Exception\FlysystemAdapterServiceInvalidException;
use Drupal\flysystem\Exception\FlysystemPluginNotFoundException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Presents the adapter drivers this container knows about.
 *
 * Definitions arrive from the build phase, where a compiler pass
 * collected every service tagged 'flysystem.adapter' — contributed
 * adapters ride the identical rail as the first-party ones. Instances
 * are not built here: each adapter is a container service, constructed
 * by Symfony with its own dependencies injected, and the manager hands
 * that service out by driver name. Definitions are alterable through
 * the standard info-alter hook so other modules can adjust labels or
 * remove drivers without touching any class.
 */
class FlysystemAdapterManager {

  /**
   * Definitions after alteration, computed once on first request.
   *
   * @var array<string, array>|null
   */
  protected ?array $alteredDefinitions = NULL;

  /**
   * Constructs the manager.
   *
   * @param array<string, array> $definitions
   *   Driver-keyed definitions collected at container build: each
   *   carries driver, label, service id, and class.
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container holding the tagged adapter services.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler for the definition alter hook.
   */
  public function __construct(
    protected readonly array $definitions,
    protected readonly ContainerInterface $container,
    protected readonly ModuleHandlerInterface $module_handler,
  ) {
  }

  /**
   * Returns the driver-keyed definitions, altered once per request.
   *
   * @return array<string, array>
   *   Definitions keyed by driver.
   */
  public function getDefinitions(): array {
    if ($this->alteredDefinitions === NULL) {
      $definitions = $this->definitions;
      $this->module_handler->alter('flysystem_adapter_info', $definitions);
      $this->alteredDefinitions = $definitions;
    }
    return $this->alteredDefinitions;
  }

  /**
   * Returns one definition by driver, or NULL when unknown.
   *
   * @param string $driver
   *   The adapter driver name.
   * @param bool $exception
   *   (optional) When TRUE (default) an unknown driver throws.
   *
   * @return array|null
   *   The definition, or NULL if unknown and $exception is FALSE.
   *
   * @throws \Drupal\flysystem\Exception\FlysystemPluginNotFoundException
   *   When the driver is unknown and $exception is TRUE.
   */
  public function getDefinition(string $driver, bool $exception = TRUE): ?array {
    $definition = $this->getDefinitions()[$driver] ?? NULL;
    if ($definition === NULL && $exception) {
      throw new FlysystemPluginNotFoundException($driver);
    }
    return $definition;
  }

  /**
   * Hands out the adapter service registered for a driver.
   *
   * @param string $driver
   *   The adapter driver name.
   *
   * @return \Drupal\flysystem\Adapter\FlysystemAdapterInterface
   *   The container-built adapter service.
   *
   * @throws \Drupal\flysystem\Exception\FlysystemPluginNotFoundException
   *   When the driver is unknown.
   * @throws \Drupal\flysystem\Exception\FlysystemAdapterServiceInvalidException
   *   When the definition's service is missing from the container or
   *   is not an adapter — wiring errors, loud rather than silent.
   */
  public function createInstance(string $driver): FlysystemAdapterInterface {
    $definition = $this->getDefinition($driver);
    $service_id = $definition['service'];
    if (!$this->container->has($service_id)) {
      throw new FlysystemAdapterServiceInvalidException(
        $driver,
        sprintf('the tagged service "%s" does not exist in the container.', $service_id)
      );
    }
    $service = $this->container->get($service_id);
    if (!$service instanceof FlysystemAdapterInterface) {
      throw new FlysystemAdapterServiceInvalidException(
        $driver,
        sprintf('the tagged service "%s" does not implement the adapter contract.', $service_id)
      );
    }
    return $service;
  }

}
