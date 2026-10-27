<?php

declare(strict_types=1);

namespace Drupal\flysystem\StreamWrapper;

use Drupal\Core\StreamWrapper\StreamWrapperInterface;

/**
 * Flysystem-backed wrapper for the public storage scheme.
 */
final class FlysystemPublicStream extends FlysystemStreamWrapperBase {

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
    return 'public';
  }

}
