<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Kernel\Core;

use Drupal\Core\File\Exception\FileNotExistsException;

/**
 * Local-storage-tier legs for the filesystem copy-contract replay.
 *
 * The shared suite pins the exception class and the message halves
 * that do not depend on a resolved path; a message naming the RESOLVED
 * source path is truthfully assertable only where the binding lives on
 * this machine's filesystem, so that full-message leg lives here and
 * runs only on local hosts.
 */
abstract class FileSystemLocalStorageReplay extends FileSystemReplay {

  /**
   * The missing-source message names the resolved path as well.
   */
  public function testMissingSourceMessageNamesResolvedPath(): void {
    $this->assertDispatchIsLive();
    $source = $this->uri('msg.txt');
    $this->expectException(FileNotExistsException::class);
    $this->expectExceptionMessage("File '$source' ('{$this->realPath('msg.txt')}') could not be copied because it does not exist");
    // The call below IS the assertion; a return would fail the test.
    $this->fileSystem->copy($source, $this->uri('msg-copy.txt'));
  }

}
