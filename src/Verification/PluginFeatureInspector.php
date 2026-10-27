<?php

declare(strict_types=1);

namespace Drupal\flysystem\Verification;

use Drupal\flysystem\Plugin\FlysystemAdapterManager;
use Drupal\flysystem\Plugin\LocalStorageInterface;

/**
 * Answers, from discovery data alone, whether a plugin serves local storage.
 *
 * Invokable so it can be injected anywhere a plugin-locality predicate is
 * expected (the settings validator and the operator factory both consume
 * this service). Answering from the discovered plugin class — a local
 * backend is one that declares the feature by implementing the marker
 * interface — means no list of known backends lives in this module and
 * nothing is ever instantiated, so the answer costs no backend code
 * execution. An unknown plugin id answers NULL, which the consumer
 * reports as its typed unknown-plugin failure.
 */
final class PluginFeatureInspector {

  /**
   * Constructs the inspector.
   *
   * @param \Drupal\flysystem\Plugin\FlysystemAdapterManager $pluginManager
   *   The adapter plugin manager whose definitions are consulted.
   */
  public function __construct(
    protected readonly FlysystemAdapterManager $pluginManager,
  ) {
  }

  /**
   * Returns the locality answer for a plugin id.
   *
   * TRUE for local storage, FALSE for any other backend, NULL when the
   * plugin id is unknown.
   *
   * @param string $pluginId
   *   The adapter plugin id.
   *
   * @return bool|null
   *   The answer as described.
   */
  public function __invoke(string $pluginId): ?bool {
    $definition = $this->pluginManager->getDefinition($pluginId, FALSE);
    if ($definition === NULL) {
      return NULL;
    }
    return is_subclass_of($definition['class'], LocalStorageInterface::class);
  }

}
