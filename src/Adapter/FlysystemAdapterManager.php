<?php

declare(strict_types=1);

namespace Drupal\flysystem\Adapter;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\flysystem\Attribute\FlysystemAdapter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Discovers, caches, and instantiates the Flysystem adapter plugins.
 *
 * This is the plugin API doing the work, shaped exactly like Search
 * API's datasource manager: every module's Plugin/FlysystemAdapter
 * directory is scanned through attribute discovery, the resulting
 * definitions are cached in the discovery cache bin (so repeat lookups
 * cost nothing and a new backend changes nothing until it is shipped),
 * the standard info hook may alter them, and createInstance() lets the
 * container-backed plugin factory build the adapter with the binding's
 * configuration passed in — module code never calls new on an adapter
 * class. Contributed adapters ride the identical rail: presence of the
 * shipping module decides presence of the adapter, and dependencies of
 * the plugin class arrive injected rather than assembled by hand.
 */
class FlysystemAdapterManager extends DefaultPluginManager {

  /**
   * Constructs the adapter manager.
   *
   * @param \Traversable $namespaces
   *   The registered module namespaces, keyed by namespace root.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   The discovery cache bin holding cached adapter definitions.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler for the definition alter hook.
   */
  public function __construct(
    #[Autowire(service: 'container.namespaces')]
    \Traversable $namespaces,
    #[Autowire(service: 'cache.discovery')]
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler,
  ) {
    parent::__construct(
      'Plugin/FlysystemAdapter',
      $namespaces,
      $module_handler,
      FlysystemAdapterInterface::class,
      FlysystemAdapter::class,
    );
    $this->setCacheBackend($cache_backend, 'flysystem_adapter_plugins');
    $this->alterInfo('flysystem_adapter_info');
  }

}
