<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Kernel\Core;

use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\Exception\FileNotExistsException;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystem;
use Drupal\Core\Site\Settings;

/**
 * Replayed from core's FileMoveTest, scheme-parameterized.
 *
 * Every stream URI names the scheme under test, so the move legs
 * dispatch to the subject wrapper and go red against the stub's
 * refusals. File-mode assertions run only for local-storage bindings.
 * The missing-source leg keeps core's scheme-less fixture paths
 * verbatim as parity floor of the non-stream branch.
 */
abstract class FileMoveReplay extends FileReplayBase {

  /**
   * Move a normal file.
   */
  public function testNormal(): void {
    $this->assertDispatchIsLive();
    // Create a file for testing.
    $uri = $this->createUri();

    // Moving to a new name.
    $desired_filepath = $this->uri($this->uploadName());
    /** @var \Drupal\Core\File\FileSystemInterface $file_system */
    $file_system = $this->container->get('file_system');
    $new_filepath = $file_system->move($uri, $desired_filepath, FileExists::Error);
    $this->assertNotFalse($new_filepath, 'Move was successful.');
    $this->assertEquals($desired_filepath, $new_filepath, 'Returned expected filepath.');
    $this->assertFileExists($new_filepath);
    $this->assertFileDoesNotExist($uri);
    if ($this->replayIsLocalStorage()) {
      $this->assertFilePermissions($new_filepath, Settings::get('file_chmod_file', FileSystem::CHMOD_FILE));
    }

    // Moving with rename.
    $desired_filepath = $this->uri($this->uploadName());
    $this->assertFileExists($new_filepath);
    $this->assertNotFalse(file_put_contents($desired_filepath, ' '), 'Created a file so a rename will have to happen.');
    $newer_filepath = $file_system->move($new_filepath, $desired_filepath, FileExists::Rename);
    $this->assertNotFalse($newer_filepath, 'Move was successful.');
    $this->assertNotEquals($desired_filepath, $newer_filepath, 'Returned expected filepath.');
    $this->assertFileExists($newer_filepath);
    $this->assertFileDoesNotExist($new_filepath);
    if ($this->replayIsLocalStorage()) {
      $this->assertFilePermissions($newer_filepath, Settings::get('file_chmod_file', FileSystem::CHMOD_FILE));
    }

    // @todo Test moving to a directory (rather than full directory/file path)
    // @todo Test creating and moving normal files (rather than streams)
  }

  /**
   * Try to move a missing file.
   */
  public function testMissing(): void {
    $this->assertDispatchIsLive();
    // Move non-existent file. Core's fixture here is scheme-less,
    // testing the non-stream branch of the file system service.
    $this->expectException(FileNotExistsException::class);
    $this->container->get('file_system')->move($this->randomMachineName(), $this->randomMachineName());
  }

  /**
   * Try to move a file onto itself.
   */
  public function testOverwriteSelf(): void {
    $this->assertDispatchIsLive();
    // Create a file for testing.
    $uri = $this->createUri();

    // Move the file onto itself without renaming shouldn't make changes.
    /** @var \Drupal\Core\File\FileSystemInterface $file_system */
    $file_system = $this->container->get('file_system');
    $this->expectException(FileException::class);
    $new_filepath = $file_system->move($uri, $uri, FileExists::Replace);
    $this->assertFalse($new_filepath, 'Moving onto itself without renaming fails.');
    $this->assertFileExists($uri);

    // Move the file onto itself with renaming will result in a new filename.
    $new_filepath = $file_system->move($uri, $uri, FileExists::Rename);
    $this->assertNotFalse($new_filepath, 'Moving onto itself with renaming works.');
    $this->assertFileDoesNotExist($uri);
    $this->assertFileExists($new_filepath);
  }

}
