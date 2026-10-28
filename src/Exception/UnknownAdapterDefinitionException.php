<?php

declare(strict_types=1);

namespace Drupal\flysystem\Exception;

/**
 * Thrown when a binding references an adapter id no source defines.
 *
 * Settings shape validation deliberately does not check adapter
 * references — only the schemes actually in use are checked, so a broken
 * binding under an unused scheme cannot throw when a different scheme is
 * touched. This exception surfaces at resolution, for that scheme only.
 */
class UnknownAdapterDefinitionException extends \InvalidArgumentException implements FlysystemConfigExceptionInterface {

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
