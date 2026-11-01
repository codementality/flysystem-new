<?php

declare(strict_types=1);

namespace Drupal\flysystem\Adapter;

use Drupal\Core\Plugin\PluginBase;
use League\Flysystem\FilesystemAdapter;

/**
 * Base class for Flysystem adapter plugins.
 *
 * Carries the plugin plumbing — configuration, id, and definition
 * arrive in the standard constructor — so a backend class contains
 * only its adapter object and, when it differs from installed, its
 * honest availability answer. Following the Search API model: the base
 * declares no behavior a consumer cannot answer through the interface.
 */
abstract class FlysystemAdapterBase extends PluginBase implements FlysystemAdapterInterface {

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    // Availability is the truth about dependencies; this default says
    // "the class needs nothing beyond the library itself", which every
    // backend whose package may be absent overrides with a real check.
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  abstract public function adapter(): FilesystemAdapter;

}
