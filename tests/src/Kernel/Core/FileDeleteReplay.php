<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Kernel\Core;

use Drupal\Core\File\Exception\NotRegularFileException;

/**
 * Replayed from core's FileDeleteTest, scheme-parameterized.
 *
 * Every URI names the scheme under test, so the delete legs dispatch
 * to the subject wrapper and go red against the stub's refusals. The
 * two legs that build symlinks are meaningful only against a real
 * filesystem: the local host runs them with links built under the
 * bound root's real path, and the in-memory host skips them by name
 * because an in-memory binding has no disk surface to link into.
 */
abstract class FileDeleteReplay extends FileReplayBase {

  /**
   * Delete a normal file.
   */
  public function testNormal(): void {
    $this->assertDispatchIsLive();
    // Create a file for testing.
    $uri = $this->createUri();

    // Delete a regular file.
    $this->assertTrue($this->container->get('file_system')->delete($uri), 'Deleted worked.');
    $this->assertFileDoesNotExist($uri);
  }

  /**
   * Try deleting a missing file.
   */
  public function testMissing(): void {
    $this->assertDispatchIsLive();
    // Try to delete a non-existing file.
    $this->assertTrue($this->container->get('file_system')->delete($this->uri($this->randomMachineName())), 'Returns true when deleting a non-existent file.');
  }

  /**
   * Try deleting a directory.
   */
  public function testDirectory(): void {
    $this->assertDispatchIsLive();
    // A directory to operate on.
    $directory = $this->createDirectory();

    // Try to delete a directory.
    try {
      $this->container->get('file_system')->delete($directory);
      $this->fail('Expected NotRegularFileException');
    }
    catch (NotRegularFileException) {
      // Ignore.
    }
    $this->assertDirectoryExists($directory);
  }

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

  /**
   * {@inheritdoc}
   *
   * The core corpus base for these tests runs against real site
   * directories, and two legs in this suite build filesystem symlinks
   * and resolve realpaths. A real (non-virtual) site directory is the
   * posture those legs assume, so this suite installs one, exactly as
   * the corpus base does.
   */
  protected function setUpFilesystem() {
    $public_file_directory = $this->siteDirectory . '/files';
    $private_file_directory = $this->siteDirectory . '/private';

    mkdir($this->siteDirectory, 0775);
    mkdir($this->siteDirectory . '/files', 0775);
    mkdir($this->siteDirectory . '/private', 0775);
    mkdir($this->siteDirectory . '/files/config/sync', 0775, TRUE);

    $this->setSetting('file_public_path', $public_file_directory);
    $this->setSetting('file_private_path', $private_file_directory);
    $this->setSetting('config_sync_directory', $this->siteDirectory . '/files/config/sync');
  }

}
