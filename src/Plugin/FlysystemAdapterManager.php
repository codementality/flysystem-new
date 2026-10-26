<?php

declare(strict_types=1);

namespace Drupal\flysystem\Plugin;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\flysystem\Exception\FlysystemPluginNotFoundException;

/**
 * Manages Flysystem adapter plugins.
 *
 * Standard core plugin system: attribute discovery under each enabled
 * module's src/Plugin/FlysystemAdapter directory, discovery caching, and
 * the alter hook — all supplied by core's DefaultPluginManager wiring,
 * mirroring Search API's BackendPluginManager.
 */
class FlysystemAdapterManager extends DefaultPluginManager {

  /**
   * Constructs the adapter plugin manager.
   *
   * @param \Traversable $namespaces
   *   The container's registered namespaces service.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   The cache backend for discovered definitions.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler for the alter hook.
   */
  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler,
  ) {
    parent::__construct(
      'Plugin/FlysystemAdapter',
      $namespaces,
      $module_handler,
      'Drupal\flysystem\Plugin\FlysystemAdapterInterface',
      'Drupal\flysystem\Attribute\FlysystemAdapter',
    );
    $this->alterInfo('flysystem_flysystem_adapter_info');
    $this->setCacheBackend($cache_backend, 'flysystem_adapter_plugins');
  }

  /**
   * Creates an adapter instance, with the module's typed not-found error.
   *
   * @param string $plugin_id
   *   The adapter plugin id.
   * @param array $configuration
   *   (optional) Plugin configuration.
   *
   * @return \Drupal\flysystem\Plugin\FlysystemAdapterInterface
   *   The instantiated adapter plugin.
   *
   * @throws \Drupal\flysystem\Exception\FlysystemPluginNotFoundException
   *   When the requested adapter plugin id does not exist.
   */
  public function createInstance($plugin_id, array $configuration = []) {
    if (!$this->getDefinition($plugin_id, FALSE)) {
      throw new FlysystemPluginNotFoundException($plugin_id);
    }
    return parent::createInstance($plugin_id, $configuration);
  }

}
