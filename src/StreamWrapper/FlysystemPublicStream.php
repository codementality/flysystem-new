<?php

declare(strict_types=1);

namespace Drupal\flysystem\StreamWrapper;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\DrupalKernel;
use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Symfony\Component\HttpFoundation\Request;

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
    // The URL answers from the site's own values — the configured base
    // URL or the base URL plus the public web path — never from any
    // wrapper class: this wrapper replaces the stock public scheme, so
    // the replacement must not borrow answers from the class it
    // replaces. Parity with the URL the webserver already serves is
    // pinned by tests comparing against core's own wrapper.
    $path = str_replace('\\', '/', $this->getTarget());
    return static::publicBaseUrl() . '/' . UrlHelper::encodePath($path);
  }

  /**
   * {@inheritdoc}
   */
  public function getDirectoryPath() {
    // The same web-path prefix core's public wrapper answers, derived
    // from the same settings and site path it reads — image-style request
    // processing and other URL-facing consumers ask the public wrapper
    // for this on every request, and a wrapper declaring local public
    // storage must map URLs exactly as the scheme it replaced. Computed
    // here rather than delegated: the replaced class may be absent or
    // replaced in turn, and a replacement must not depend on it.
    return static::publicBasePath();
  }

  /**
   * The public scheme's web path prefix, from settings and the site path.
   *
   * Mirrors the computation core's public wrapper performs: the
   * file_public_path setting when set, otherwise the active site path
   * plus its files folder. Only settings, the container's site.path
   * parameter, and — before any container exists — the kernel's site
   * path discovery participate; none of those is a scheme class.
   *
   * @return string
   *   The public web path prefix.
   */
  private static function publicBasePath(): string {
    if (\Drupal::hasService('kernel')) {
      $site_path = \Drupal::getContainer()->getParameter('site.path');
    }
    else {
      $site_path = DrupalKernel::findSitePath(Request::createFromGlobals());
    }
    return (string) Settings::get('file_public_path', $site_path . '/files');
  }

  /**
   * The public scheme's base URL, from settings or base URL plus prefix.
   *
   * The file_public_base_url setting wins when configured; otherwise the
   * site's own base URL global plus the public web path, exactly the
   * parts core composes the same answer from.
   *
   * @return string
   *   The external base URL for the public scheme.
   */
  private static function publicBaseUrl(): string {
    $settings_base_url = Settings::get('file_public_base_url', '');
    if ($settings_base_url) {
      return (string) $settings_base_url;
    }
    return $GLOBALS['base_url'] . '/' . static::publicBasePath();
  }

}
