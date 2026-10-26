<?php

declare(strict_types=1);

namespace Drupal\flysystem\Exception;

/**
 * Thrown when a publicly accessible local root resolves outside the webroot.
 */
class PublicRootOutsideWebrootException extends \RuntimeException implements FlysystemConfigExceptionInterface {

  /**
   * Constructs the exception naming the resolved root.
   *
   * @param string $scheme
   *   The publicly accessible scheme.
   * @param string $resolvedRoot
   *   The lexically resolved root (anchored, never realpath-ed).
   * @param string $appRoot
   *   The webroot containment boundary.
   */
  public function __construct(string $scheme, string $resolvedRoot, string $appRoot) {
    parent::__construct(sprintf("Webroot containment violation: scheme '%s' is publicly accessible but its local adapter root '%s' resolves outside the webroot '%s'. Public storage outside the docroot is unreachable by URL mapping.", $scheme, $resolvedRoot, $appRoot));
  }

}
