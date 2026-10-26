<?php

declare(strict_types=1);

namespace Drupal\flysystem\Exception;

/**
 * Thrown when a binding references an adapter id no source defines.
 *
 * #5 deliberately defers this question to resolution; it surfaces here,
 * lazily, for the touched scheme only (B4).
 */
class UnknownAdapterDefinitionException extends \InvalidArgumentException {

  /**
   * Constructs the exception naming both the reference and its scheme.
   *
   * @param string $adapterId
   *   The referenced, undefined adapter id.
   * @param string $scheme
   *   The scheme whose binding made the reference.
   */
  public function __construct(string $adapterId, string $scheme) {
    parent::__construct(sprintf("Binding for scheme '%s' references adapter id '%s', which no definition source defines.", $scheme, $adapterId));
  }

}
