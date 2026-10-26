<?php

declare(strict_types=1);

namespace Drupal\flysystem\Exception;

/**
 * Thrown when a settings layer section or entry has the wrong shape.
 */
class InvalidSettingsStructureException extends \InvalidArgumentException implements FlysystemConfigExceptionInterface {

  /**
   * Constructs the exception for an offending path.
   *
   * @param string $path
   *   The settings path, such as 'adapters[main][plugin]'.
   * @param string $expected
   *   What was expected there.
   */
  public function __construct(string $path, string $expected) {
    parent::__construct(sprintf('Invalid settings structure at %s: expected %s.', $path, $expected));
  }

}
