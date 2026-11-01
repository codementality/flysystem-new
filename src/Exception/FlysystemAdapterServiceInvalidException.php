<?php

declare(strict_types=1);

namespace Drupal\flysystem\Exception;

/**
 * Thrown when a known driver's adapter service cannot be used.
 *
 * The definition says a driver exists but its tagged service is missing
 * from the container or is not an adapter — a wiring defect that must
 * surface as a named, typed error instead of a confusing file failure.
 */
class FlysystemAdapterServiceInvalidException extends \RuntimeException implements FlysystemConfigExceptionInterface {

  /**
   * Constructs the exception for a driver with an unusable service.
   *
   * @param string $driver
   *   The adapter driver whose service cannot be used.
   * @param string $reason
   *   What is wrong.
   */
  public function __construct(string $driver, string $reason) {
    parent::__construct(sprintf('Adapter "%s" cannot be used: %s', $driver, $reason));
  }

}
