<?php

declare(strict_types=1);

namespace Drupal\flysystem\Exception;

/**
 * Thrown when a scheme name is outside core's registerable grammar.
 */
class InvalidSchemeNameException extends \InvalidArgumentException implements FlysystemConfigExceptionInterface {

  /**
   * Constructs the exception for an offending scheme name.
   *
   * @param string $scheme
   *   The invalid scheme.
   */
  public function __construct(string $scheme) {
    parent::__construct(sprintf("Invalid scheme name '%s': schemes must match ^[A-Za-z0-9_-]+$ (core's registerable grammar).", $scheme));
  }

}
