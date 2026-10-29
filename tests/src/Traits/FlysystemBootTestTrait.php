<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Traits;

/**
 * Shared scratch-root mechanics for tests that bind schemes to real folders.
 *
 * The helpers create and remove local directories the way a deployed site
 * would see them: directories are created with the mode core's own file
 * layer uses, and removal unlocks read-only trees first so a leg that
 * deliberately left a locked folder behind cannot survive the run.
 */
trait FlysystemBootTestTrait {

  /**
   * The directory mode a deployed Drupal creates its file directories with.
   *
   * @see \Drupal\Core\File\FileSystemInterface::CHMOD_DIRECTORY
   */
  protected const SCRATCH_ROOT_MODE = 0775;

  /**
   * Creates a scratch root when absent, with deployed-site directory mode.
   *
   * @param string $dir
   *   Absolute path of the scratch root.
   *
   * @return string
   *   The same path, ready for use.
   */
  protected function createScratchRoot(string $dir): string {
    if (!is_dir($dir)) {
      mkdir($dir, static::SCRATCH_ROOT_MODE, TRUE);
    }
    return $dir;
  }

  /**
   * Best-effort recursive removal of a scratch root.
   *
   * @param string $dir
   *   Absolute path of the scratch root.
   */
  protected function removeScratchRoot(string $dir): void {
    if (!is_dir($dir)) {
      return;
    }
    @chmod($dir, 0755);
    foreach (scandir($dir) ?: [] as $entry) {
      if ($entry === '.' || $entry === '..') {
        continue;
      }
      $path = $dir . '/' . $entry;
      is_dir($path) ? $this->removeScratchRoot($path) : @unlink($path);
    }
    @rmdir($dir);
  }

}
