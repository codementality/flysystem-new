<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Kernel\Core;

use Drupal\Core\File\FileExists;

/**
 * Replayed from core's FileSaveDataTest, scheme-parameterized.
 *
 * Body copied from the kernel corpus (unmanaged save-data); every URI
 * names the scheme under test, and the dispatch probe runs before the
 * first I/O leg so nothing here can pass vacuously.
 */
abstract class FileSaveDataReplay extends FileReplayBase {

  /**
   * Tests the file_unmanaged_save_data() function.
   */
  public function testFileSaveData(): void {
    $this->assertDispatchIsLive();
    $contents = $this->randomMachineName(8);
    $this->setSetting('file_chmod_file', 0777);

    // No filename.
    /** @var \Drupal\Core\File\FileSystemInterface $file_system */
    $file_system = \Drupal::service('file_system');

    // Provide a filename.
    $filepath = $file_system->saveData($contents, $this->uri('asdf.txt'), FileExists::Replace);
    $this->assertNotFalse($filepath, 'Unnamed file saved correctly.');
    $this->assertEquals('asdf.txt', basename($filepath), 'File was named correctly.');
    $this->assertEquals($contents, file_get_contents($filepath), 'Contents of the file are correct.');
  }

}
