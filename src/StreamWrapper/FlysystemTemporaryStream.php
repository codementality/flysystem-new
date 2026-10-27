<?php

declare(strict_types=1);

namespace Drupal\flysystem\StreamWrapper;

use Drupal\Core\StreamWrapper\StreamWrapperInterface;

/**
 * Flysystem-backed wrapper for the temporary storage scheme.
 */
final class FlysystemTemporaryStream extends FlysystemStreamWrapperBase {

  /**
   * {@inheritdoc}
   */
  public static function getType() {
    return StreamWrapperInterface::LOCAL_HIDDEN;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSchemeName(): string {
    return 'temporary';
  }

}
