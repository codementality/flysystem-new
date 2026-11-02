<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Traits;

/**
 * Collects E_USER_DEPRECATED notices raised by a code read.
 *
 * Tests that intentionally exercise deprecated configuration read paths
 * use this instead of letting notices reach the PHPUnit deprecation
 * channel: the count and wording of notices is an assertion, not noise.
 */
trait DeprecationCaptureTrait {

  /**
   * Runs the given callable, capturing deprecation notices it triggers.
   *
   * @param callable $read
   *   The code to run. Any value it writes back through bound variables
   *   is visible to the caller after the run.
   *
   * @return string[]
   *   The E_USER_DEPRECATED messages raised during the run, in order.
   */
  protected function captureDeprecations(callable $read): array {
    $notices = [];
    set_error_handler(
      static function (int $severity, string $message) use (&$notices): bool {
        if ($severity === E_USER_DEPRECATED) {
          $notices[] = $message;
          return TRUE;
        }
        return FALSE;
      }
    );
    try {
      $read();
    }
    finally {
      restore_error_handler();
    }
    return $notices;
  }

}
