<?php

declare(strict_types=1);

namespace Drupal\flysystem\Factory;

use Drupal\flysystem\Definition\DefinitionChecksumTrait;
use Drupal\flysystem\Exception\FlysystemPackageMissingException;
use Drupal\flysystem\Adapter\FlysystemAdapterManager;
use Drupal\flysystem\Resolution\BindingResolver;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Visibility;
use Psr\Log\LoggerInterface;

/**
 * Turns a scheme into a working filesystem operator, or into nothing.
 *
 * The full foundation pipeline in one call: resolve the scheme through the
 * binding resolver (which validates the touched scheme lazily), ask the
 * resolved adapter service to build its operator, and memoize the result
 * for the request under the definition's identity checksum. A scheme
 * that resolves to core-native (unbound or disabled) yields NULL and
 * builds nothing — that is what keeps unbound schemes untouched.
 *
 * Path posture is decided by the local-storage feature, never by plugin
 * names: local bindings get the library's default traversal behavior
 * (interior '..' resolves within the bound root); anything else is built
 * strict (interior '..' rejected outright). The posture rides to the
 * plugin inside the merged configuration under the library's own reserved
 * key, which adapter plugins forward to their operator.
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
   * @param \Drupal\flysystem\Adapter\FlysystemAdapterManager $pluginManager
   *   The adapter manager handing out adapter services.
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
      $adapter = $this->pluginManager->createInstance($plugin_id);
      if (!$adapter->isAvailable()) {
        $exception = new FlysystemPackageMissingException($plugin_id);
        $this->logger->error($exception->getMessage(), ['scheme' => $scheme, 'link' => NULL]);
        throw $exception;
      }
      // One assembly path for every backend: the adapter service builds
      // the league adapter object; the operator and its operation
      // defaults are composed here from the merged configuration.
      $this->operators[$key] = new Filesystem(
        $adapter->adapter($config),
        $this->filesystemOptions($config),
      );
    }
    return $this->operators[$key];
  }

  /**
   * Operator-level defaults merged into every operation's config.
   *
   * Default visibility PUBLIC is what makes the file mode actually apply
   * on writes (the library only chmods when visibility is present).
   *
   * @param array $config
   *   The merged configuration, including the posture decision.
   *
   * @return array<string, mixed>
   *   Default operation config for the operator.
   */
  private function filesystemOptions(array $config): array {
    $options = ['visibility' => $config['visibility'] ?? Visibility::PUBLIC];
    if (isset($config['directory_visibility'])) {
      $options['directory_visibility'] = (string) $config['directory_visibility'];
    }
    if (array_key_exists('allow_relative_path_traversal', $config)) {
      // The posture decision rides in the merged configuration under the
      // library's own key, forwarded untouched to the operator.
      $options['allow_relative_path_traversal'] = (bool) $config['allow_relative_path_traversal'];
    }
    return $options;
  }

}
