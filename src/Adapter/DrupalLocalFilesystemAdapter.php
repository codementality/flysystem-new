<?php

declare(strict_types=1);

namespace Drupal\flysystem\Adapter;

use League\Flysystem\Config;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnixVisibility\VisibilityConverter;

/**
 * The league local adapter, corrected so Drupal directory modes survive.
 *
 * Flysystem grew up as Laravel's filesystem layer, and its built-in
 * permission defaults are Laravel's; the visibility converter exists so
 * other platforms replace them. Files land corrected already — the
 * library chmods files after writing them. Directories do not: every
 * directory-creation path in the vendored adapter (parents implied by
 * a write, copy, or move; the lazily created root; createDirectory's
 * own mkdir) passes the mode to PHP's mkdir(), which the process umask
 * silently masks — a Drupal posture of 0775 arrives as 0755, and a
 * site's file_chmod_directory of 0770 arrives as 0750. Core avoids this
 * by chmod-ing directories it creates; this class does the same, in the
 * one place that knows the library's real paths.
 *
 * The rule is exactly two halves, and both are load-bearing:
 * directories this operation CREATES get the declared mode; directories
 * that ALREADY EXIST are never rewritten — the site's own permissions
 * decisions outrank any write that happens to pass through.
 * (createDirectory's explicit request is the one exception the library
 * itself implements, and that behavior is inherited unchanged.)
 */
final class DrupalLocalFilesystemAdapter extends LocalFilesystemAdapter {

  /**
   * The root prefix, computed the same way the library's prefixer does.
   *
   * @var string
   */
  private string $rootPrefix;

  /**
   * Constructs the umask-corrected local adapter over an absolute root.
   *
   * @param string $root
   *   The absolute, already-resolved root directory.
   * @param \League\Flysystem\UnixVisibility\VisibilityConverter $visibilityConverter
   *   The platform's visibility converter; its directory answers are
   *   what get chmod-ed onto newly created directories.
   * @param int $writeFlags
   *   Flags for file writes (library default: LOCK_EX).
   * @param int $linkHandling
   *   Link handling posture (library default: disallow).
   * @param bool $lazyRootCreation
   *   Whether the root is created on first use rather than eagerly.
   */
  public function __construct(
    string $root,
    VisibilityConverter $visibilityConverter,
    int $writeFlags = \LOCK_EX,
    int $linkHandling = self::DISALLOW_LINKS,
    bool $lazyRootCreation = FALSE,
  ) {
    parent::__construct($root, $visibilityConverter, $writeFlags, $linkHandling, lazyRootCreation: $lazyRootCreation);
    $this->rootPrefix = rtrim($root, '/') . '/';
    $this->visibility = $visibilityConverter;
  }

  /**
   * The platform's converter, kept for mode resolution on chmod.
   *
   * @var \League\Flysystem\UnixVisibility\VisibilityConverter
   */
  private VisibilityConverter $visibility;

  /**
   * {@inheritdoc}
   *
   * After the library creates any part of $dirname, every part it had
   * to create is chmod-ed to the declared directory mode. Existing
   * directories are captured before the call and excluded from it.
   */
  protected function ensureDirectoryExists(string $dirname, int $visibility): void {
    $created = $this->missingAncestors($dirname);
    parent::ensureDirectoryExists($dirname, $visibility);
    $this->applyMode($created, $visibility);
  }

  /**
   * {@inheritdoc}
   *
   * The library's createDirectory mkdirs recursively with the mode
   * subject to the umask (existing directories it chmods itself, and
   * that inherited behavior is untouched). Here the created target and
   * any created ancestors get the same chmod-after-create as every
   * other directory path.
   */
  public function createDirectory(string $path, Config $config): void {
    $location = $this->rootPrefix . ltrim($path, '/');
    $created = $this->missingAncestors($location);
    parent::createDirectory($path, $config);
    $visibilityName = $config->get(Config::OPTION_VISIBILITY, $config->get(Config::OPTION_DIRECTORY_VISIBILITY));
    $mode = $visibilityName === NULL
      ? $this->visibility->defaultForDirectories()
      : $this->visibility->forDirectory($visibilityName);
    $this->applyMode($created, $mode);
  }

  /**
   * Lists the path and its ancestors that do not exist yet.
   *
   * @param string $path
   *   An absolute directory path about to be created (recursively).
   *
   * @return string[]
   *   The missing components, deepest first; empty when $path exists.
   */
  private function missingAncestors(string $path): array {
    if (is_dir($path)) {
      return [];
    }
    $missing = [];
    $current = $path;
    while ($current !== '/' && !is_dir($current)) {
      $missing[] = $current;
      $current = dirname($current);
    }
    return $missing;
  }

  /**
   * Applies the directory mode to directories we just created.
   *
   * Best effort, mirroring the library's own posture for ancillary
   * operations: a failed chmod on a created directory is not turned
   * into a failed write. The guarantee these steps exist for —
   * umask-independent directory modes — is asserted by the local
   * plugin's tests, which run where chmod works.
   *
   * @param string[] $directories
   *   Paths that did not exist before the library ran.
   * @param int $mode
   *   The declared directory mode.
   */
  private function applyMode(array $directories, int $mode): void {
    foreach ($directories as $directory) {
      if (is_dir($directory)) {
        @chmod($directory, $mode);
        clearstatcache(TRUE, $directory);
      }
    }
  }

}
