<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Kernel\Core;

use Drupal\Core\Database\Database;
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
   * Tests asynchronous directory creation.
   *
   * Image style generation can result in many calls to create similar
   * directory paths. This test forks the process to create the same
   * situation. The directories are now under the scheme under test, so
   * each fork dispatches a mkdir attempt to the subject wrapper.
   *
   * Local-storage tier: the assertion observes storage from the
   * parent process, which is only meaningful for backends whose
   * store is shared across processes. A per-process in-memory store
   * cannot show the children's writes to the parent — the leg is a
   * property of shared storage, so it lives here rather than
   * skipping by name over there.
   */
  public function testMultiplePrepareDirectory(): void {
    $this->assertDispatchIsLive();
    if (!function_exists('pcntl_fork')) {
      $this->markTestSkipped('Requires the pcntl_fork() function');
    }
    $directories = [];
    for ($i = 1; $i <= 10; $i++) {
      $directories[] = $this->uri('a/b/c/d/e/f/g/h/' . $i);
    }

    $file_system = $this->container->get('file_system');

    $time_to_start = microtime(TRUE) + 0.1;
    // This loop creates a new fork to create each directory.
    foreach ($directories as $directory) {
      $pid = pcntl_fork();
      if ($pid == -1) {
        $this->fail("Error forking");
      }
      elseif ($pid == 0) {
        // Sleep so that all the forks start preparing the directory at the
        // same time.
        usleep((int) (($time_to_start - microtime(TRUE)) * 1000000));
        $file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
        exit();
      }
    }

    // This while loop holds the parent process until all the child threads
    // are complete - at which point the script continues to execute.
    while (pcntl_waitpid(0, $status) != -1);

    foreach ($directories as $directory) {
      $this->assertDirectoryExists($directory);
    }

    // Remove the database connection because it will have been destroyed
    // when the forks exited. This allows
    // \Drupal\KernelTests\KernelTestBase::tearDown() to reopen it.
    Database::removeConnection('default');
  }

  /**
   * Directory chmod legs within what storage can express.
   *
   * Modes as configured, and the limits of binary visibility named.
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

    // Deviation leg, named: numeric permission modes outside the
    // public/private distinction cannot be expressed through the
    // filesystem operator — visibility is a two-value question
    // (vendor/league/flysystem/src/FilesystemWriter.php,
    // setVisibility()), so a chmod(0444) request maps to the public
    // side and cannot strip the site-declared write bits. The honest,
    // testable answer: the directory stays writable at the mode the
    // site declares. A read-only directory is not a state this
    // wrapper can create through the operator.
    @$file_system->chmod($directory, 0444);
    $this->assertTrue(
      $file_system->prepareDirectory($directory, 0),
      'Numeric read-only modes are inexpressible through the operator; the declared writability stands (documented deviation).'
    );

    // Test directory permission modification.
    $this->setSetting('file_chmod_directory', 0777);
    $this->assertTrue($file_system->prepareDirectory($directory, FileSystemInterface::MODIFY_PERMISSIONS), 'No error reported when making directory writable.');

    // Test that the directory has the correct permissions.
    $this->assertDirectoryPermissions($directory, 0777, 'file_chmod_directory setting is respected.');
  }

}
