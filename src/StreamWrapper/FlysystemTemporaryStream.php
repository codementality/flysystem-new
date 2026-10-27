<?php

declare(strict_types=1);

namespace Drupal\flysystem\StreamWrapper;

use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\Url;

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

  /**
   * {@inheritdoc}
   */
  public function getName() {
    // Wording identical to the stock temporary scheme so the interface —
    // and its translations — reads the same as core's own wrapper.
    return $this->t('Temporary files');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('Temporary local files for upload and previews.');
  }

  /**
   * {@inheritdoc}
   */
  public function getExternalUrl() {
    // The same temporary-file URL core's temporary wrapper builds.
    $path = str_replace('\\', '/', $this->getTarget());
    return Url::fromRoute('system.temporary', [], ['absolute' => TRUE, 'query' => ['file' => $path]])->toString();
  }

}
