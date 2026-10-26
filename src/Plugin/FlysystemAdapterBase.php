<?php

declare(strict_types=1);

namespace Drupal\flysystem\Plugin;

use Drupal\Core\Plugin\PluginBase;

/**
 * Common base for adapter plugins: configuration plumbing only.
 *
 * Concrete adapters live in their own modules (this one for local,
 * submodules for the rest) and implement the two contract methods.
 * Deliberately minimal — behavior belongs to backends, not the base.
 */
abstract class FlysystemAdapterBase extends PluginBase implements FlysystemAdapterInterface {

  /**
   * {@inheritdoc}
   */
  public function getConfiguration() {
    return $this->configuration;
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration) {
    $this->configuration = $configuration + $this->defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [];
  }

}
