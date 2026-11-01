<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Kernel\Core;

use Drupal\Core\File\FileSystem;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Replayed from core's ScanDirectoryTest, scheme-parameterized.
 *
 * Core scans a shipped fixture directory by real path; this replay
 * builds the same fixture tree under the scheme under test and scans
 * those URIs, so every leg dispatches to the subject wrapper. The
 * seeding itself runs through the wrapper — it is a leg, not a fixture
 * bypass — so with the wrapper unimplemented every test goes red at
 * the refused write or directory creation, which is the awaited
 * behavior being named. The scan option legs (mask, nomask, key,
 * recurse, min_depth, ignore-directories setting) keep core's bodies
 * with only the scan target parameterized.
 */
#[CoversClass(FileSystem::class)]
abstract class ScanDirectoryReplay extends FileReplayBase {

  /**
   * The location of the fixture files, under the scheme under test.
   *
   * @var string
   */
  protected $path;

  /**
   * The file system.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->path = $this->uri('fixtures');
    $this->fileSystem = $this->container->get('file_system');
  }

  /**
   * Check the format of the returned values.
   *
   * @legacy-covers ::scanDirectory
   */
  public function testReturn(): void {
    $this->assertDispatchIsLive();
    $this->seedJavaScriptFixtures();

    // Grab a listing of all the JavaScript files and check that they're
    // passed to the callback.
    $all_files = $this->fileSystem->scanDirectory($this->path, '/^javascript-/');
    ksort($all_files);
    $this->assertCount(2, $all_files, 'Found two, expected javascript files.');

    // Check the first file.
    $file = reset($all_files);
    $this->assertEquals(key($all_files), $file->uri, 'Correct array key was used for the first returned file.');
    $this->assertEquals($this->path . '/javascript-1.txt', $file->uri, 'First file name was set correctly.');
    $this->assertEquals('javascript-1.txt', $file->filename, 'First basename was set correctly');
    $this->assertEquals('javascript-1', $file->name, 'First name was set correctly.');

    // Check the second file.
    $file = next($all_files);
    $this->assertEquals(key($all_files), $file->uri, 'Correct array key was used for the second returned file.');
    $this->assertEquals($this->path . '/javascript-2.script', $file->uri, 'Second file name was set correctly.');
    $this->assertEquals('javascript-2.script', $file->filename, 'Second basename was set correctly');
    $this->assertEquals('javascript-2', $file->name, 'Second name was set correctly.');
  }

  /**
   * Check that the callback function is called correctly.
   *
   * @legacy-covers ::scanDirectory
   */
  public function testOptionCallback(): void {
    $this->assertDispatchIsLive();
    $this->seedJavaScriptFixtures();

    // When nothing is matched nothing should be passed to the callback.
    $all_files = $this->fileSystem->scanDirectory($this->path, '/^NON-EXISTING-FILENAME/', ['callback' => '\\Drupal\\Tests\\flysystem\\Kernel\\Core\\ScanDirectoryReplay::replayFileScanCallback']);
    $this->assertCount(0, $all_files, 'No files were found.');
    $results = self::replayFileScanCallback();
    self::replayFileScanCallbackReset();
    $this->assertCount(0, $results, 'No files were passed to the callback.');

    // Grab a listing of all the JavaScript files and check that they're
    // passed to the callback.
    $all_files = $this->fileSystem->scanDirectory($this->path, '/^javascript-/', ['callback' => '\\Drupal\\Tests\\flysystem\\Kernel\\Core\\ScanDirectoryReplay::replayFileScanCallback']);
    $this->assertCount(2, $all_files, 'Found two, expected javascript files.');
    $results = self::replayFileScanCallback();
    self::replayFileScanCallbackReset();
    $this->assertCount(2, $results, 'Files were passed to the callback.');
  }

  /**
   * Check that the no-mask parameter is honored.
   *
   * @legacy-covers ::scanDirectory
   */
  public function testOptionNoMask(): void {
    $this->assertDispatchIsLive();
    $this->seedJavaScriptFixtures();

    // Grab a listing of all the JavaScript files.
    $all_files = $this->fileSystem->scanDirectory($this->path, '/^javascript-/');
    $this->assertCount(2, $all_files, 'Found two, expected javascript files.');

    // Now use the nomask parameter to filter out the .script file.
    $filtered_files = $this->fileSystem->scanDirectory($this->path, '/^javascript-/', ['nomask' => '/.script$/']);
    $this->assertCount(1, $filtered_files, 'Filtered correctly.');
  }

  /**
   * Check that key parameter sets the return value's key.
   *
   * @legacy-covers ::scanDirectory
   */
  public function testOptionKey(): void {
    $this->assertDispatchIsLive();
    $this->seedJavaScriptFixtures();

    // "filename", for the path starting with $dir.
    $expected = [$this->path . '/javascript-1.txt', $this->path . '/javascript-2.script'];
    $actual = array_keys($this->fileSystem->scanDirectory($this->path, '/^javascript-/', ['key' => 'filepath']));
    sort($actual);
    $this->assertEquals($expected, $actual, 'Returned the correct values for the filename key.');

    // "basename", for the basename of the file.
    $expected = ['javascript-1.txt', 'javascript-2.script'];
    $actual = array_keys($this->fileSystem->scanDirectory($this->path, '/^javascript-/', ['key' => 'filename']));
    sort($actual);
    $this->assertEquals($expected, $actual, 'Returned the correct values for the basename key.');

    // "name" for the name of the file without an extension.
    $expected = ['javascript-1', 'javascript-2'];
    $actual = array_keys($this->fileSystem->scanDirectory($this->path, '/^javascript-/', ['key' => 'name']));
    sort($actual);
    $this->assertEquals($expected, $actual, 'Returned the correct values for the name key.');

    // Invalid option that should default back to "filename".
    $expected = [$this->path . '/javascript-1.txt', $this->path . '/javascript-2.script'];
    $actual = array_keys($this->fileSystem->scanDirectory($this->path, '/^javascript-/', ['key' => 'INVALID']));
    sort($actual);
    $this->assertEquals($expected, $actual, 'An invalid key defaulted back to the default.');
  }

  /**
   * Check that the recurse option descends into subdirectories.
   *
   * @legacy-covers ::scanDirectory
   */
  public function testOptionRecurse(): void {
    $this->assertDispatchIsLive();
    $this->seedJavaScriptFixtures();

    // The fixture subdirectory's parent is the bound root.
    $root = $this->uri('');
    $files = $this->fileSystem->scanDirectory($root, '/^javascript-/', ['recurse' => FALSE]);
    $this->assertEmpty($files, "Without recursion couldn't find javascript files.");

    $files = $this->fileSystem->scanDirectory($root, '/^javascript-/', ['recurse' => TRUE]);
    $this->assertCount(2, $files, 'With recursion we found the expected javascript files.');
  }

  /**
   * Tests the min_depth option of scanDirectory().
   *
   * @legacy-covers ::scanDirectory
   */
  public function testOptionMinDepth(): void {
    $this->assertDispatchIsLive();
    $this->seedJavaScriptFixtures();

    $files = $this->fileSystem->scanDirectory($this->path, '/^javascript-/', ['min_depth' => 0]);
    $this->assertCount(2, $files, 'No minimum-depth gets files in current directory.');

    $files = $this->fileSystem->scanDirectory($this->path, '/^javascript-/', ['min_depth' => 1]);
    $this->assertEmpty($files, 'Minimum-depth of 1 successfully excludes files from current directory.');
  }

  /**
   * Tests ::scanDirectory obeys 'file_scan_ignore_directories' setting.
   *
   * @legacy-covers ::scanDirectory
   */
  public function testIgnoreDirectories(): void {
    $this->assertDispatchIsLive();
    // Mirror core's shipped fixture tree inside the bound root.
    $this->createUri('IgnoreDirectories/a.txt', 'a');
    $this->createDirectory('IgnoreDirectories/frontend_framework');
    $this->createUri('IgnoreDirectories/frontend_framework/b.txt', 'b');
    $directory = $this->uri('IgnoreDirectories');

    $files = $this->fileSystem->scanDirectory($directory, '/\.txt$/');
    $this->assertCount(2, $files, '2 text files found when not ignoring directories.');

    $this->setSetting('file_scan_ignore_directories', ['frontend_framework']);
    $files = $this->fileSystem->scanDirectory($directory, '/\.txt$/');
    $this->assertCount(1, $files, '1 text files found when ignoring directories called "frontend_framework".');

    // Ensure that the directories in file_scan_ignore_directories are escaped
    // using preg_quote.
    $this->setSetting('file_scan_ignore_directories', ['frontend.*']);
    $files = $this->fileSystem->scanDirectory($directory, '/\.txt$/');
    $this->assertCount(2, $files, '2 text files found when ignoring a directory that is not there.');

    $files = $this->fileSystem->scanDirectory($directory, '/\.txt$/', ['nomask' => '/^something_thing_else$/']);
    $this->assertCount(2, $files, '2 text files found when an "nomask" option is passed in.');
  }

  /**
   * Creates the fixture tree through the wrapper under test.
   *
   * These writes are legs of the replay, not setup outside the subject:
   * with the wrapper unimplemented, seeding itself fails at the refused
   * stream open or directory creation.
   */
  protected function seedJavaScriptFixtures(): void {
    $this->createDirectory('fixtures');
    $this->createUri('fixtures/javascript-1.txt', 'javascript one');
    $this->createUri('fixtures/javascript-2.script', 'javascript two');
  }

  /**
   * Scan callback recording the files passed to it.
   *
   * Stands in for the file_test module's helper in core's own body; the
   * replay suite carries no file_test dependency.
   *
   * @param mixed $filepath
   *   The scanned file; when NULL the recorded list is returned.
   * @param bool $reset
   *   Whether to clear the recorded list instead.
   *
   * @return array
   *   The recorded files.
   */
  public static function replayFileScanCallback($filepath = NULL, bool $reset = FALSE): array {
    static $files = [];

    if ($reset) {
      $files = [];
    }
    elseif ($filepath) {
      $files[] = $filepath;
    }

    return $files;
  }

  /**
   * Clears the scan callback's recorded list.
   */
  public static function replayFileScanCallbackReset(): void {
    self::replayFileScanCallback(NULL, TRUE);
  }

}
