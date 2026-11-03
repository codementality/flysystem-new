<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Kernel\Core;

use Drupal\Component\FileSecurity\FileSecurity;
use Drupal\Component\FileSystem\FileSystem;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\system\Hook\SystemHooks;

/**
 * Replayed from core's DirectoryTest, scheme-parameterized.
 *
 * The directory legs of this corpus operate on stream URIs; here they
 * operate on URIs of the scheme under test, so they dispatch to the
 * subject wrapper and go red against the stub's refusals. Directory
 * mode assertions run only for local-storage bindings. Some legs in
 * the corpus deliberately operate on absolute real paths (creating
 * directories outside any stream, scanning shipped fixtures): those
 * stay verbatim as the parity floor of core's own behavior, and they
 * need a real site directory rather than the virtual one the kernel
 * default provides — exactly what the corpus base installs, so this
 * suite installs it the same way. The .htaccess legs address the
 * public files directory because that is the directory the core
 * service under test protects; they are parity floor, not wrapper
 * behavior.
 */
abstract class DirectoryReplay extends FileReplayBase {

  /**
   * Tests local directory handling functions.
   */
  public function testFileCheckLocalDirectoryHandling(): void {
    $this->assertDispatchIsLive();
    $site_path = $this->container->getParameter('site.path');
    $directory = $site_path . '/files';

    // Check a new recursively created local directory for correct file
    // system permissions.
    $parent = $this->randomMachineName();
    $child = $this->randomMachineName();

    // Files directory already exists.
    $this->assertDirectoryExists($directory);
    // Make files directory writable only.
    $old_mode = fileperms($directory);

    // Create the directories.
    $parent_path = $directory . DIRECTORY_SEPARATOR . $parent;
    $child_path = $parent_path . DIRECTORY_SEPARATOR . $child;
    /** @var \Drupal\Core\File\FileSystemInterface $file_system */
    $file_system = $this->container->get('file_system');
    $this->assertTrue($file_system->mkdir($child_path, 0775, TRUE), 'No error reported when creating new local directories.');

    // Ensure new directories also exist.
    $this->assertDirectoryExists($parent_path);
    $this->assertDirectoryExists($child_path);

    // Check that new directory permissions were set properly.
    $this->assertDirectoryPermissions($parent_path, 0775);
    $this->assertDirectoryPermissions($child_path, 0775);

    // Check that existing directory permissions were not modified.
    // (The harness permission helper takes a pre-masked mode value,
    // so the recorded fileperms mode is masked here.)
    $this->assertDirectoryPermissions($directory, $old_mode & 0777);

    // Check creating a directory using an absolute path.
    $absolute_path = $file_system->realpath($directory) . DIRECTORY_SEPARATOR . $this->randomMachineName() . DIRECTORY_SEPARATOR . $this->randomMachineName();
    $this->assertTrue($file_system->mkdir($absolute_path, 0775, TRUE), 'No error reported when creating new absolute directories.');
    $this->assertDirectoryPermissions($absolute_path, 0775);
  }

  /**
   * Tests directory handling functions.
   */
  public function testFileCheckDirectoryHandling(): void {
    $this->assertDispatchIsLive();
    // A directory to operate on, under the scheme under test.
    $directory = $this->uri($this->randomMachineName() . '/' . $this->randomMachineName());
    $this->assertDirectoryDoesNotExist($directory);

    // Non-existent directory.
    /** @var \Drupal\Core\File\FileSystemInterface $file_system */
    $file_system = $this->container->get('file_system');
    $this->assertFalse($file_system->prepareDirectory($directory, 0), 'Error reported for non-existing directory.');

    // Make a directory.
    $this->assertTrue($file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY), 'No error reported when creating a new directory.');

    // Make sure directory actually exists.
    $this->assertDirectoryExists($directory);
    // Remove .htaccess file to then test that it gets re-created. The
    // htaccess writer protects the public files directory; these legs
    // stay on core's own scheme as parity floor.
    @$file_system->unlink('public://.htaccess');
    $this->assertFileDoesNotExist('public://.htaccess');
    $this->container->get('file.htaccess_writer')->ensure();
    $this->assertFileExists('public://.htaccess');

    // Remove .htaccess file again to test that it is re-created by a cron
    // run.
    @$file_system->unlink('public://.htaccess');
    $this->assertFileDoesNotExist('public://.htaccess');
    $systemCron = new SystemHooks();
    $systemCron->cron();
    $this->assertFileExists('public://.htaccess');

    // Verify contents of .htaccess file.
    $file = file_get_contents('public://.htaccess');
    $this->assertEquals(FileSecurity::htaccessLines(FALSE), $file, 'The .htaccess file contains the proper content.');
  }

  /**
   * Tests the file paths of newly created files.
   */
  public function testFileCreateNewFilepath(): void {
    $this->assertDispatchIsLive();
    // First we test against an imaginary file that does not exist in a
    // directory. Core's fixture here is a shipped real path, testing
    // the non-stream branch: parity floor.
    $basename = 'xyz.txt';
    $directory = 'core/misc';
    $original = $directory . '/' . $basename;
    /** @var \Drupal\Core\File\FileSystemInterface $file_system */
    $file_system = $this->container->get('file_system');
    $path = $file_system->createFilename($basename, $directory);
    $this->assertEquals($original, $path, "New filepath $path equals $original.");

    // Then we test against a file that already exists within that directory.
    $basename = 'druplicon.png';
    $original = $directory . '/' . $basename;
    $expected = $directory . '/druplicon_0.png';
    $path = $file_system->createFilename($basename, $directory);
    $this->assertEquals($expected, $path, "Creating a new filepath from $path equals $original (expected $expected).");

    // @todo Finally we copy a file into a directory several times, to ensure
    // a properly iterating filename suffix.
  }

  /**
   * Tests the destination file path.
   *
   * This will test the filepath for a destination based on passed flags and
   * whether or not the file exists.
   *
   * If a file exists, ::getDestinationFilename($destination, $replace) will
   * either return:
   * - the existing filepath, if $replace is FileExists::Replace
   * - a new filepath if FileExists::Rename
   * - an error (returning FALSE) if FileExists::Error.
   * If the file doesn't currently exist, then it will simply return the
   * filepath.
   */
  public function testFileDestination(): void {
    $this->assertDispatchIsLive();
    // First test for non-existent file. Core's fixture here is shipped
    // real paths, testing the non-stream branch: parity floor.
    $destination = 'core/misc/xyz.txt';
    /** @var \Drupal\Core\File\FileSystemInterface $file_system */
    $file_system = $this->container->get('file_system');
    $path = $file_system->getDestinationFilename($destination, FileExists::Replace);
    $this->assertEquals($destination, $path, 'Non-existing filepath destination is correct with FileExists::Replace.');
    $path = $file_system->getDestinationFilename($destination, FileExists::Rename);
    $this->assertEquals($destination, $path, 'Non-existing filepath destination is correct with FileExists::Rename.');
    $path = $file_system->getDestinationFilename($destination, FileExists::Error);
    $this->assertEquals($destination, $path, 'Non-existing filepath destination is correct with FileExists::Error.');

    $destination = 'core/misc/druplicon.png';
    $path = $file_system->getDestinationFilename($destination, FileExists::Replace);
    $this->assertEquals($destination, $path, 'Existing filepath destination remains the same with FileExists::Replace.');
    $path = $file_system->getDestinationFilename($destination, FileExists::Rename);
    $this->assertNotEquals($destination, $path, 'A new filepath destination is created when filepath destination already exists with FileExists::Rename.');
    $path = $file_system->getDestinationFilename($destination, FileExists::Error);
    $this->assertFalse($path, 'An error is returned when filepath destination already exists with FileExists::Error.');

    // Invalid UTF-8 causes an exception.
    $this->expectException(FileException::class);
    $this->expectExceptionMessage("Invalid filename 'a\xFFtest\x80€.txt'");
    $file_system->getDestinationFilename("core/misc/a\xFFtest\x80€.txt", FileExists::Replace);
  }

  /**
   * Ensure that the getTempDirectory() method always returns a value.
   */
  public function testFileDirectoryTemp(): void {
    $this->assertDispatchIsLive();
    $tmp_directory = $this->container->get('file_system')->getTempDirectory();
    $this->assertNotEmpty($tmp_directory);
    $this->assertEquals($tmp_directory, FileSystem::getOsTemporaryDirectory());
  }

  /**
   * Tests directory creation.
   */
  public function testDirectoryCreation(): void {
    $this->assertDispatchIsLive();
    /** @var \Drupal\Core\File\FileSystemInterface $file_system */
    $file_system = $this->container->get('file_system');

    // mkdir() recursion should work with or without a trailing slash.
    // Core's fixture here is the real site files directory: parity
    // floor of the absolute-path branch.
    $dir = $this->siteDirectory . '/files';
    $this->assertTrue($file_system->mkdir($dir . '/foo/bar', 0775, TRUE));
    $this->assertTrue($file_system->mkdir($dir . '/foo/baz/', 0775, TRUE));
  }

  /**
   * {@inheritdoc}
   *
   * The corpus base runs these tests against real site directories, and
   * several legs here create directories by absolute path and assert
   * their modes on the real filesystem. That is the posture this replay
   * installs, the same as the corpus base does.
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
