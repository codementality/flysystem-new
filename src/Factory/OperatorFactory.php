<?php

declare(strict_types=1);

namespace Drupal\flysystem\Factory;

use Drupal\flysystem\Definition\DefinitionChecksumTrait;
use Drupal\flysystem\Exception\FlysystemPackageMissingException;
use Drupal\flysystem\Plugin\FlysystemAdapterManager;
use Drupal\flysystem\Resolution\BindingResolver;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;

/**
 * Turns a scheme into a working filesystem operator, or into nothing.
 *
 * The full foundation pipeline in one call: resolve the scheme through the
 * binding resolver (which validates the touched scheme lazily), ask the
 * resolved plugin to build its operator, and memoize the result for the
 * rest of the request under the definition's identity checksum. A scheme
 * that resolves to core-native (unbound or disabled) yields NULL and
 * builds nothing — that is what keeps unbound schemes untouched.
 *
 * Path posture is decided by the local-storage feature, never by plugin
 * names: local bindings get the library's default traversal behavior
 * (interior '..' resolves within the bound root); anything else is built
 * strict (interior '..' rejected outright). The posture rides to the
 * plugin inside the merged configuration under the library's own reserved
 * key, which adapter plugins forward to their operator.
 *
 * Not final: consumers that hold this factory are exercised with a
 * stand-in answering operatorFor(), so the seam between storage and file
 * operations can be tested without building a real backend.
 */
class OperatorFactory {

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
   * @param \Drupal\flysystem\Plugin\FlysystemAdapterManager $pluginManager
   *   The adapter plugin manager instantiating plugins.
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
    protected readonly FlysystemAdapterManager $pluginManager,
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
      $plugin = $this->pluginManager->createInstance($plugin_id, $config);
      if (!$plugin->isAvailable()) {
        $exception = new FlysystemPackageMissingException($plugin_id);
        $this->logger->error($exception->getMessage(), ['scheme' => $scheme, 'link' => NULL]);
        throw $exception;
      }
      $this->operators[$key] = $plugin->buildFilesystem($config);
    }
    return $this->operators[$key];
  }

}
