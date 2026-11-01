<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Kernel\Core;

use Drupal\Core\File\Exception\DirectoryNotReadyException;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\Exception\FileExistsException;
use Drupal\Core\File\Exception\FileNotExistsException;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystem;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Replayed from core's FileSystemTest, scheme-parameterized.
 *
 * Core seeds these fixtures with touch() against the stream URI, and
 * this replay keeps that shape: with the wrapper not yet implemented,
 * the seeded file never comes into existence and each leg goes red at
 * the stub's refusal, which is the awaited behavior being named.
 *
 * Exception-message handling: core's expectation for a missing source
 * embeds the resolved on-disk path of the file, and that string is a
 * truth only when this host's binding names an on-disk location. The
 * local host builds the full message from the URI plus its real path;
 * for a non-local binding the wrapper's realpath answer is the
 * wrapper's own, not a path on disk, so the in-memory host asserts
 * only the exception class and the URI-containing prefix of the
 * message (the harness realpath helper answers NULL there).
 */
#[CoversClass(FileSystem::class)]
abstract class FileSystemReplay extends FileReplayBase {

  /**
   * The file handler under test.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->fileSystem = $this->container->get('file_system');
  }

  /**
   * Tests ensure file exists before copy.
   *
   * @legacy-covers ::copy
   */
  public function testEnsureFileExistsBeforeCopy(): void {
    $this->assertDispatchIsLive();
    // We need to compute the exception message here because it will
    // include the 'real' path to the file, which varies with the
    // host's binding.
    $source = $this->uri('test.txt');
    $this->expectException(FileNotExistsException::class);
    $real = $this->replaySampleRealPath('test.txt');
    if ($real !== NULL) {
      $this->expectExceptionMessage("File '$source' ('$real') could not be copied because it does not exist");
    }
    else {
      $this->expectExceptionMessage("File '$source'");
    }

    $this->fileSystem->copy($source, $this->uri('test-copy.txt'));
  }

  /**
   * Tests destination directory failure on copy.
   *
   * @legacy-covers ::copy
   */
  public function testDestinationDirectoryFailureOnCopy(): void {
    $this->assertDispatchIsLive();
    $source = $this->uri('test.txt');
    $destination = $this->uri('subdirectory/test.txt');
    $this->expectException(DirectoryNotReadyException::class);
    $this->expectExceptionMessage("The specified file '$source' could not be copied because the destination directory '{$this->uri('subdirectory')}' is not properly configured. This may be caused by a problem with file or directory permissions.");
    touch($source);
    // The subdirectory under the scheme under test has not been
    // created, so prepareDirectory() fails, causing copy() to throw
    // DirectoryNotReadyException.
    $this->fileSystem->copy($source, $destination);
  }

  /**
   * Tests copy failure if file already exists.
   */
  public function testCopyFailureIfFileAlreadyExists(): void {
    $this->assertDispatchIsLive();
    $uri = $this->uri('test.txt');
    $this->expectException(FileExistsException::class);
    $this->expectExceptionMessage("File '$uri' could not be copied because a file by that name already exists in the destination directory ('')");
    touch($uri);
    $this->fileSystem->copy($uri, $uri, FileExists::Error);
  }

  /**
   * Tests copy failure if self overwrite.
   */
  public function testCopyFailureIfSelfOverwrite(): void {
    $this->assertDispatchIsLive();
    $uri = $this->uri('test.txt');
    $this->expectException(FileException::class);
    $this->expectExceptionMessage("'$uri' could not be copied because it would overwrite itself");
    touch($uri);
    $this->fileSystem->copy($uri, $uri, FileExists::Replace);
  }

  /**
   * Tests copy self rename.
   */
  public function testCopySelfRename(): void {
    $this->assertDispatchIsLive();
    $uri = $this->uri('test.txt');
    touch($uri);
    $this->fileSystem->copy($uri, $uri);
    $this->assertFileExists($this->uri('test_0.txt'));
  }

  /**
   * Tests successful copy.
   *
   * @legacy-covers ::copy
   */
  public function testSuccessfulCopy(): void {
    $this->assertDispatchIsLive();
    touch($this->uri('test.txt'));
    $this->fileSystem->copy($this->uri('test.txt'), $this->uri('test-copy.txt'));
    $this->assertFileExists($this->uri('test-copy.txt'));
  }

}
