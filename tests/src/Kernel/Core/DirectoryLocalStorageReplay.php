<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Kernel\Core;

use Drupal\Core\File\FileSystemInterface;

/**
 * Local-storage-tier legs for the directory replay.
 *
 * Directory modes — read-only refusal, writable restoration, the
 * file_chmod_directory posture — exist only where the binding's
 * storage is the server's own filesystem, so they live here and run
 * only on local hosts. Windows mode semantics are unchanged from
 * core's own guard.
 */
abstract class DirectoryLocalStorageReplay extends DirectoryReplay {

  /**
   * Directory chmod legs: refusal when read-only, modes as configured.
   */
  public function testDirectoryPermissionLegs(): void {
    $this->assertDispatchIsLive();
    if (str_starts_with(PHP_OS, 'WIN')) {
      $this->markTestSkipped('PHP on Windows has no useful read-only directory mode; core\'s own guard.');
    }
    /** @var \Drupal\Core\File\FileSystemInterface $file_system */
    $file_system = $this->container->get('file_system');
    $directory = $this->uri($this->randomMachineName());

    // Create it through the graph first.
    $this->assertTrue($file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY), 'No error reported when creating a new directory.');

    // Make directory read only: writes must now be refused.
    @$file_system->chmod($directory, 0444);
    $this->assertFalse($file_system->prepareDirectory($directory, 0), 'Error reported for a non-writable directory.');

    // Test directory permission modification.
    $this->setSetting('file_chmod_directory', 0777);
    $this->assertTrue($file_system->prepareDirectory($directory, FileSystemInterface::MODIFY_PERMISSIONS), 'No error reported when making directory writable.');

    // Test that the directory has the correct permissions.
    $this->assertDirectoryPermissions($directory, 0777, 'file_chmod_directory setting is respected.');
  }

}
