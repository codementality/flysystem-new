<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Kernel\Core;

use Drupal\Core\File\FileSystem;
use Drupal\Core\Site\Settings;

/**
 * Local-storage-tier legs for the unmanaged-copy replay.
 *
 * Unix file modes exist only for storage on the server's own
 * filesystem; the shared suite asserts the copy contract, this tier
 * asserts the mode contract, and only the local host extends it.
 */
abstract class FileCopyLocalStorageReplay extends FileCopyReplay {

  /**
   * A copied file lands with the site's file mode.
   */
  public function testCopiedFileCarriesSiteFileMode(): void {
    $this->assertDispatchIsLive();
    $uri = $this->createUri();
    $new_filepath = $this->container->get('file_system')->copy($uri, $this->uri($this->uploadName()));
    $this->assertNotFalse($new_filepath, 'Copy succeeded before its mode can be checked.');
    $this->assertFilePermissions($new_filepath, Settings::get('file_chmod_file', FileSystem::CHMOD_FILE));
  }

  /**
   * A rename-suffixed copy lands with the site's file mode too.
   */
  public function testRenamedCopyCarriesSiteFileMode(): void {
    $this->assertDispatchIsLive();
    $uri = $this->createUri();
    $taken = $this->uri($this->uploadName());
    $this->assertNotFalse(file_put_contents($taken, ' '), 'Created a file so the copy will rename.');
    $newer = $this->container->get('file_system')->copy($uri, $taken);
    $this->assertNotFalse($newer, 'Rename copy succeeded before its mode can be checked.');
    $this->assertFilePermissions($newer, Settings::get('file_chmod_file', FileSystem::CHMOD_FILE));
  }

}
