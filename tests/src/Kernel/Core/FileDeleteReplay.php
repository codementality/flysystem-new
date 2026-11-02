<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Kernel\Core;

use Drupal\Core\File\Exception\NotRegularFileException;

/**
 * Replayed from core's FileDeleteTest, scheme-parameterized.
 *
 * Every URI names the scheme under test, so the delete legs dispatch
 * to the subject wrapper and go red against the stub's refusals.
 * Symlink fixtures are a property of local filesystems only and live
 * at the local tier (FileDeleteLocalStorageReplay), inherited by the
 * local host alone — no conditional legs, no skips.
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

    // What this leg tests, in both halves: delete() must REFUSE a
    // directory (NotRegularFileException; if the call below returns
    // instead, fail() ends the test), AND the refusal must leave the
    // directory intact. expectException() cannot express the second
    // half — the method stops at the first assertion — hence the
    // explicit try/catch, copied from core's corpus.
    try {
      $this->container->get('file_system')->delete($directory);
      $this->fail('Expected NotRegularFileException');
    }
    catch (NotRegularFileException) {
      // Ignore.
    }
    $this->assertDirectoryExists($directory);
  }

}
