<?php

declare(strict_types=1);

namespace Drupal\flysystem\Exception;

/**
 * Thrown when a referenced plugin id is not a known adapter plugin.
 */
class UnknownAdapterPluginException extends \InvalidArgumentException implements FlysystemConfigExceptionInterface {

  /**
   * Constructs the exception for an unresolvable plugin reference.
   *
   * @param string $pluginId
   *   The unknown plugin id.
   * @param string $adapterId
   *   The adapter definition referencing it.
   */
  public function __construct(string $pluginId, string $adapterId) {
    parent::__construct(sprintf("Adapter '%s' references unknown plugin '%s': it is not a registered adapter plugin.", $adapterId, $pluginId));
  }

}
