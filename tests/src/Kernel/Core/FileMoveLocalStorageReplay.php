<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Kernel\Core;

use Drupal\Core\File\FileSystem;
use Drupal\Core\Site\Settings;

/**
 * Local-storage-tier legs for the unmanaged-move replay.
 *
 * Same rule as every local tier here: modes belong to hosts whose
 * storage is the server's own filesystem, so the shared suite keeps
 * the move contract and this tier owns the mode contract.
 */
abstract class FileMoveLocalStorageReplay extends FileMoveReplay {

  /**
   * A moved file lands at the destination with the site's file mode.
   */
  public function testMovedFileCarriesSiteFileMode(): void {
    $this->assertDispatchIsLive();
    $uri = $this->createUri();
    $new_filepath = $this->container->get('file_system')->move($uri, $this->uri($this->uploadName()));
    $this->assertNotFalse($new_filepath, 'Move succeeded before its mode can be checked.');
    $this->assertFilePermissions($new_filepath, Settings::get('file_chmod_file', FileSystem::CHMOD_FILE));
  }

}
