<?php

declare(strict_types=1);

namespace Drupal\flysystem\Factory;

use Drupal\flysystem\Definition\DefinitionChecksumTrait;
use Drupal\flysystem\Exception\FlysystemPackageMissingException;
use Drupal\flysystem\Adapter\FlysystemAdapterManager;
use Drupal\flysystem\Resolution\BindingResolver;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;

/**
 * Turns a scheme into a working filesystem operator, or into nothing.
 *
 * The full foundation pipeline in one call: resolve the scheme through
 * the binding resolver (which validates the touched scheme lazily), let
 * the adapter plugin manager build the resolved plugin with the merged
 * configuration, hand the produced adapter object to the filesystem
 * builder, and memoize the resulting operator for the request under the
 * definition's identity checksum. A scheme that resolves to core-native
 * (unbound or disabled) yields NULL and builds nothing — that is what
 * keeps unbound schemes untouched.
 *
 * Path posture is decided by the local-storage feature, never by
 * plugin names: local bindings get the library's default traversal
 * behavior (interior '..' resolves within the bound root); anything
 * else is built strict (interior '..' rejected outright). The posture
 * rides to the plugin inside the merged configuration under the
 * library's own reserved key.
 */
final class OperatorFactory {

  use DefinitionChecksumTrait;

  /**
   * Memoized operators, keyed by definition identity checksum.
   *
   * @var array<string, \League\Flysystem\FilesystemOperator>
   */
  protected array $operators = [];

  /**
   * Constructs the factory.
   *
   * @param \Drupal\flysystem\Resolution\BindingResolver $resolver
   *   The binding resolver supplying (and validating) definitions.
   * @param \Drupal\flysystem\Adapter\FlysystemAdapterManager $adapterManager
   *   The adapter plugin manager building plugins on demand.
   * @param \Drupal\flysystem\Factory\FilesystemBuilder $builder
   *   The seam that composes operators from adapter objects.
   * @param callable $isLocalMode
   *   Predicate answering whether a plugin id serves local storage —
   *   built in production from the plugin's declared features.
   * @param \Psr\Log\LoggerInterface $logger
   *   This module's logger channel: build failures are recorded, with
   *   their scheme context, before the exception propagates to whoever
   *   is using the scheme.
   */
  public function __construct(
    protected readonly BindingResolver $resolver,
    protected readonly FlysystemAdapterManager $adapterManager,
    protected readonly FilesystemBuilder $builder,
    callable $isLocalMode,
    protected readonly LoggerInterface $logger,
  ) {
    $this->isLocalMode = \Closure::fromCallable($isLocalMode);
  }

  /**
   * The plugin-locality predicate (feature declaration, not naming).
   *
   * @var \Closure
   */
  protected \Closure $isLocalMode;

  /**
   * Returns the operator serving a scheme, or NULL for core-native ones.
   *
   * @param string $scheme
   *   The scheme being operated on.
   *
   * @return \League\Flysystem\FilesystemOperator|null
   *   The memoized operator, or NULL when the scheme resolves to
   *   core-native storage.
   *
   * @throws \Drupal\flysystem\Exception\InvalidSchemeNameException
   * @throws \Drupal\flysystem\Exception\InvalidAdapterIdException
   * @throws \Drupal\flysystem\Exception\InvalidSettingsStructureException
   * @throws \Drupal\flysystem\Exception\UnknownAdapterPluginException
   * @throws \Drupal\flysystem\Exception\PublicRootOutsideWebrootException
   * @throws \Drupal\flysystem\Exception\UnknownAdapterDefinitionException
   *   All deferred to the resolver, for the touched scheme only.
   * @throws \Drupal\flysystem\Exception\FlysystemPackageMissingException
   *   When the resolved plugin reports it cannot work right now.
   */
  public function operatorFor(string $scheme): ?FilesystemOperator {
    $definition = $this->resolver->resolve($scheme);
    if ($definition === NULL) {
      return NULL;
    }

    $plugin_id = $definition['plugin'];
    $config = $definition['config'];
    $config['allow_relative_path_traversal'] = ($this->isLocalMode)($plugin_id) ? TRUE : FALSE;

    $key = $this->definitionChecksum([
      'plugin' => $plugin_id,
      'config' => $config,
    ]);
    if (!isset($this->operators[$key])) {
      // The plugin factory builds the adapter; this module never
      // calls new on an adapter class. The merged configuration flows
      // into the plugin constructor.
      $adapter = $this->adapterManager->createInstance($plugin_id, $config);
      if (!$adapter->isAvailable()) {
        $exception = new FlysystemPackageMissingException($plugin_id);
        $this->logger->error($exception->getMessage(), ['scheme' => $scheme, 'link' => NULL]);
        throw $exception;
      }
      // One assembly path for every backend: the adapter plugin
      // produces the league adapter object; the filesystem builder
      // — the module's only Filesystem construction site — composes
      // the operator from it and the merged configuration.
      $this->operators[$key] = $this->builder->build($adapter->adapter(), $config);
    }
    return $this->operators[$key];
  }

}
