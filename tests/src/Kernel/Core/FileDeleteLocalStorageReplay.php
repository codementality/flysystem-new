<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Kernel\Core;

use Drupal\Core\File\Exception\NotRegularFileException;

/**
 * Local-storage-tier legs for the delete replay: symlink fixtures.
 *
 * Symbolic links are a property of the server's own filesystem — a
 * remote binding has no link to make, so these legs live at this tier
 * and only the local host inherits them. The shared suite holds the
 * legs every backend must honor.
 */
abstract class FileDeleteLocalStorageReplay extends FileDeleteReplay {

  /**
   * Tests deleting a symlink to a directory.
   */
  public function testSymlinkDirectory(): void {
    $this->assertDispatchIsLive();
    // A directory to operate on.
    $path = $this->randomMachineName();
    $this->createDirectory($path);
    $directory = $this->realPath($path);
    $link = $this->realPath($this->randomMachineName());
    symlink($directory, $link);
    $this->assertDirectoryExists($link);

    $this->container->get('file_system')->delete($link);
    $this->assertDirectoryExists($directory);
    $this->assertDirectoryDoesNotExist($link);
  }

  /**
   * Tests deleting using a symlinked directory using stream wrappers.
   *
   * Note that this does not work because the path will be resolved to
   * the real path in the stream wrapper and not the link.
   */
  public function testSymlinkDirectoryStreamWrappers(): void {
    $this->assertDispatchIsLive();
    // A directory to operate on.
    $path = $this->randomMachineName();
    $this->createDirectory($path);
    $link = $this->uri($this->randomMachineName());
    symlink($this->realPath($path), $this->realPath(basename($link)));
    $this->assertDirectoryExists($link);

    $this->expectExceptionMessage("Cannot delete '$link' because it is a directory. Use deleteRecursive() instead.");
    $this->expectException(NotRegularFileException::class);
    $this->container->get('file_system')->delete($link);
  }

}
