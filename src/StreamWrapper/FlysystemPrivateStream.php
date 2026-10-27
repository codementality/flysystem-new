<?php

declare(strict_types=1);

namespace Drupal\flysystem\StreamWrapper;

use Drupal\Core\StreamWrapper\StreamWrapperInterface;

/**
 * Flysystem-backed wrapper for the private storage scheme.
 */
final class FlysystemPrivateStream extends FlysystemStreamWrapperBase {

  /**
   * {@inheritdoc}
   */
  public static function getType() {
    return StreamWrapperInterface::LOCAL_NORMAL;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSchemeName(): string {
    return 'private';
  }

}
