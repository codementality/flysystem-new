<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Kernel\Core;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;

/**
 * Replayed from core's ReadOnlyStreamWrapperTest, scheme-parameterized.
 *
 * Core's corpus registers a second, read-only scheme whose wrapper
 * refuses write operations while reads work; this replay adopts that
 * parameterization pattern. The harness scheme stays registered, and
 * a second scheme (fsreadonly) is registered here with a test-scope
 * wrapper that mirrors core's read-only refusal shape over the subject
 * wrapper class. From the harness point of view the suite then runs on
 * the read-only scheme: its URI builder, dispatch probe, and real-path
 * helper all follow the registered scheme under test.
 *
 * The write-rejection legs should hold against the read-only posture
 * even once the wrapper is implemented, and the read legs (open for
 * reading, shared lock, close) must reach the subject wrapper — today
 * they go red at the stub's refusal. Core seeds the readable file by
 * writing it directly to the real directory the read-only wrapper maps
 * onto its scheme; that seed surface is disk-bound, so the local host
 * performs it under the bound root's real path and the in-memory host
 * skips this test by name: an in-memory binding has no disk surface to
 * seed, and seeding through the operator cannot be done before the
 * scheme is registered and bound.
 */
abstract class ReadOnlyReplay extends FileReplayBase {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('stream_wrapper.fsreadonly', TestReadOnlyStreamWrapper::class)
      ->addTag('stream_wrapper', ['scheme' => 'fsreadonly']);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The replayed legs run against the read-only scheme; the harness
    // helpers follow this property. The container was already built
    // with both schemes registered when the parent set-up ran.
    $this->scheme = 'fsreadonly';
  }

  /**
   * Tests read-only specific behavior.
   */
  public function testReadOnlyBehavior(): void {
    $this->assertDispatchIsLive();
    $type = TestReadOnlyStreamWrapper::getType();
    // Checks that the stream wrapper type is not declared as writable.
    $this->assertSame(0, $type & StreamWrapperInterface::WRITE);
    // Checks that the stream wrapper type is declared as local.
    $this->assertSame(1, $type & StreamWrapperInterface::LOCAL);

    // Generate a test file.
    $filename = $this->randomMachineName();
    $filepath = $this->seedReadOnlyFile($filename);

    // Generate a read-only stream wrapper instance.
    $uri = $this->uri($filename);
    $this->container->get('stream_wrapper_manager')->getViaScheme($this->scheme);

    $file_system = $this->container->get('file_system');
    // Attempt to open a file in read/write mode.
    $handle = @fopen($uri, 'r+');
    $this->assertFalse($handle, 'Unable to open a file for reading and writing with the read-only stream wrapper.');
    // Attempt to open a file in binary read mode.
    $handle = fopen($uri, 'rb');
    $this->assertNotFalse($handle, 'Able to open a file for reading in binary mode with the read-only stream wrapper.');
    $this->assertTrue(fclose($handle), 'Able to close file opened in binary mode using the read_only stream wrapper.');
    // Attempt to open a file in text read mode.
    $handle = fopen($uri, 'rt');
    $this->assertNotFalse($handle, 'Able to open a file for reading in text mode with the read-only stream wrapper.');
    $this->assertTrue(fclose($handle), 'Able to close file opened in text mode using the read_only stream wrapper.');
    // Attempt to open a file in read mode.
    $handle = fopen($uri, 'r');
    $this->assertNotFalse($handle, 'Able to open a file for reading with the read-only stream wrapper.');
    // Attempt to change file permissions.
    $this->assertFalse(@chmod($uri, 0777), 'Unable to change file permissions when using read-only stream wrapper.');
    // Attempt to acquire an exclusive lock for writing.
    $this->assertFalse(@flock($handle, LOCK_EX | LOCK_NB), 'Unable to acquire an exclusive lock using the read-only stream wrapper.');
    // Attempt to obtain a shared lock.
    $this->assertTrue(flock($handle, LOCK_SH | LOCK_NB), 'Able to acquire a shared lock using the read-only stream wrapper.');
    // Attempt to release a shared lock.
    $this->assertTrue(flock($handle, LOCK_UN | LOCK_NB), 'Able to release a shared lock using the read-only stream wrapper.');
    // Attempt to truncate the file.
    $this->assertFalse(@ftruncate($handle, 0), 'Unable to truncate using the read-only stream wrapper.');
    // Attempt to write to the file.
    $this->assertEquals(0, @fwrite($handle, $this->randomMachineName()), 'Unable to write to file using the read-only stream wrapper.');
    // Attempt to flush output to the file.
    $this->assertFalse(@fflush($handle), 'Unable to flush output to file using the read-only stream wrapper.');
    // Attempt to close the stream. (Suppress errors, as fclose triggers
    // fflush.)
    $this->assertTrue(fclose($handle), 'Able to close file using the read_only stream wrapper.');
    // Test the rename() function.
    $this->assertFalse(@rename($uri, $this->uri('new_name.txt')), 'Unable to rename files using the read-only stream wrapper.');
    // Test the unlink() function.
    $this->assertTrue(@$file_system->unlink($uri), 'Able to unlink file using read-only stream wrapper.');
    $this->assertFileExists($filepath);

    // Test the mkdir() function by attempting to create a directory.
    $dirname = $this->randomMachineName();
    $dir = $this->realPath($dirname);
    $read_only_dir = $this->uri($dirname);
    /** @var \Drupal\Core\File\FileSystemInterface $file_system */
    $file_system = $this->container->get('file_system');
    $this->assertFalse(@$file_system->mkdir($read_only_dir, 0775, 0), 'Unable to create directory with read-only stream wrapper.');
    // Create a temporary directory for testing purposes.
    $this->assertTrue($file_system->mkdir($dir), 'Test directory created.');
    // Test the rmdir() function by attempting to remove the directory.
    $this->assertFalse(@$file_system->rmdir($read_only_dir), 'Unable to delete directory with read-only stream wrapper.');
    // Remove the temporary directory.
    $file_system->rmdir($dir);
  }

  /**
   * Seeds the readable fixture by writing it onto the bound root's disk.
   *
   * This mirrors core's own seed: the read-only wrapper is never
   * expected to accept this write — it is placed directly where the
   * binding maps the scheme, so a later read leg can legitimately find
   * it. Meaningful only when the binding names a real location.
   *
   * @param string $filename
   *   The file name inside the bound root.
   *
   * @return string
   *   The real path of the seeded file.
   */
  protected function seedReadOnlyFile(string $filename): string {
    $filepath = $this->realPath($filename);
    if (!is_dir($this->replayRoot)) {
      mkdir($this->replayRoot, 0775, TRUE);
    }
    file_put_contents($filepath, $filename);
    return $filepath;
  }

}
