<?php

declare(strict_types=1);

namespace Drupal\flysystem\StreamWrapper;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\StreamWrapper\PublicStream;
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

  /**
   * {@inheritdoc}
   */
  public function getName() {
    // Wording identical to the stock public scheme so the interface — and
    // its translations — reads the same as core's own wrapper.
    return $this->t('Public files');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('Public local files served by the webserver.');
  }

  /**
   * {@inheritdoc}
   */
  public function getExternalUrl() {
    // The same URL computation core's public wrapper performs: base URL
    // from the site setting or the request root, path-encoded. Reusing
    // the static helper keeps behavior that other code (file system
    // settings form) already depends on in step with this wrapper.
    $path = str_replace('\\', '/', $this->getTarget());
    return PublicStream::baseUrl() . '/' . UrlHelper::encodePath($path);
  }

  /**
   * {@inheritdoc}
   */
  public function getDirectoryPath() {
    // The same web-path prefix core's public wrapper answers, from core's
    // own helper: the image-style request processor and other URL-facing
    // consumers derive paths from it, and a wrapper declaring local public
    // storage must map URLs exactly as the scheme it replaced.
    return PublicStream::basePath();
  }

}
