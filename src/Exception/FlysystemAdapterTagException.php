<?php

declare(strict_types=1);

namespace Drupal\flysystem\Exception;

/**
 * Thrown at container build when a 'flysystem.adapter' tag is malformed.
 *
 * A tag missing its driver, claiming a driver two services already
 * share, or using a spelling the adapter-id grammar cannot accept, is
 * a module-authoring error. It fails loudly where it is knowable —
 * build time — rather than becoming a missing driver at file time.
 */
class FlysystemAdapterTagException extends \InvalidArgumentException implements FlysystemConfigExceptionInterface {

  /**
   * Constructs the exception for an offending service.
   *
   * @param string $service_id
   *   The misconfigured service name.
   * @param string $reason
   *   What is wrong and how to fix it.
   */
  public function __construct(string $service_id, string $reason) {
    parent::__construct(sprintf('Invalid flysystem.adapter service "%s": %s.', $service_id, $reason));
  }

}
