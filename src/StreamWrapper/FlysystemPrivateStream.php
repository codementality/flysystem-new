<?php

declare(strict_types=1);

namespace Drupal\flysystem\StreamWrapper;

use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\Url;

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

  /**
   * {@inheritdoc}
   */
  public function getName() {
    // Wording identical to the stock private scheme so the interface — and
    // its translations — reads the same as core's own wrapper.
    return $this->t('Private files');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('Private local files served by Drupal.');
  }

  /**
   * {@inheritdoc}
   */
  public function getExternalUrl() {
    // The same download-route URL core's private wrapper builds; private
    // files are served through PHP regardless of where they are stored.
    $path = str_replace('\\', '/', $this->getTarget());
    return Url::fromRoute(
      'system.private_file_download',
      ['filepath' => $path],
      ['absolute' => TRUE, 'path_processing' => FALSE]
    )->toString();
  }

}
