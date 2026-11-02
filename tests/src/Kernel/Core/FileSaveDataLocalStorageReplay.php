<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Kernel\Core;

use Drupal\Core\File\FileExists;

/**
 * Local-storage-tier legs for the save-data replay.
 *
 * Unix file modes are a property of the server's own filesystem: a
 * binding whose storage is not local has no mode for fileperms() to
 * read, so permission assertions belong to a host that declared local
 * storage — never to a conditional inside the shared suite. The local
 * host inherits these legs through this class; the in-memory host does
 * not extend it at all.
 */
abstract class FileSaveDataLocalStorageReplay extends FileSaveDataReplay {

  /**
   * The saved file carries the site's configured file mode.
   */
  public function testSavedFileCarriesSiteFileMode(): void {
    $this->assertDispatchIsLive();
    $this->setSetting('file_chmod_file', 0777);
    /** @var \Drupal\Core\File\FileSystemInterface $file_system */
    $file_system = \Drupal::service('file_system');
    $filepath = $file_system->saveData('modes', $this->uri('modes.txt'), FileExists::Replace);
    $this->assertNotFalse($filepath, 'File saved before its mode can be checked.');
    $this->assertFilePermissions($filepath, 0777, 'A local write must land with the site file_chmod_file mode, umask-independent.');
  }

}
