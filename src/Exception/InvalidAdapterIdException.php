<?php

declare(strict_types=1);

namespace Drupal\flysystem\Exception;

/**
 * Thrown when an adapter id is outside the declared grammar.
 */
class InvalidAdapterIdException extends \InvalidArgumentException implements FlysystemConfigExceptionInterface {

  /**
   * Constructs the exception for an offending adapter id.
   *
   * @param string $adapterId
   *   The invalid adapter id.
   */
  public function __construct(string $adapterId) {
    parent::__construct(sprintf("Invalid adapter id '%s': adapter ids must match ^[a-z0-9_]+$.", $adapterId));
  }

}
