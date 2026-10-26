<?php

declare(strict_types=1);

namespace Drupal\flysystem\Util;

/**
 * Lexical path anchoring shared by validation and the local adapter.
 *
 * Implements the ruling on relative roots (B2a): anchor against the app
 * root — never the process CWD — and collapse '.'/'..' segments purely
 * lexically. realpath() is deliberately not used anywhere: it would break
 * not-yet-created roots and silently resolve symlinks the OS is supposed
 * to see. This class performs NO filesystem access of any kind.
 */
final class LexicalPath {

  /**
   * Resolves a configured path against an app root.
   *
   * @param string $path
   *   The configured path. Absolute paths are kept (still normalized);
   *   relative paths are anchored to the app root.
   * @param string $appRoot
   *   The app root to anchor against (trailing separators normalized).
   *
   * @return string
   *   The lexically resolved absolute path.
   */
  public static function resolve(string $path, string $appRoot): string {
    $appRoot = rtrim($appRoot, '/');
    $anchored = str_starts_with($path, '/') ? $path : $appRoot . '/' . $path;
    $segments = [];
    foreach (explode('/', $anchored) as $segment) {
      if ($segment === '' || $segment === '.') {
        continue;
      }
      if ($segment === '..') {
        if ($segments && end($segments) !== '..') {
          array_pop($segments);
        }
        elseif (!$segments) {
          // Above the absolute root there is nothing; '..' of '/' stays
          // at '/' — the POSIX lexical rule, not a side effect.
          continue;
        }
        else {
          $segments[] = '..';
        }
        continue;
      }
      $segments[] = $segment;
    }
    return '/' . implode('/', $segments);
  }

  /**
   * TRUE when the resolved path is the base root or below it.
   *
   * @param string $resolved
   *   A resolved path from resolve().
   * @param string $appRoot
   *   The app root boundary.
   *
   * @return bool
   *   Containment result.
   */
  public static function isInside(string $resolved, string $appRoot): bool {
    $appRoot = rtrim($appRoot, '/');
    return $resolved === $appRoot || str_starts_with($resolved, $appRoot . '/');
  }

}
