<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Kernel\Core;

/**
 * Handle-semantics legs for the subject stream wrapper.
 *
 * These are named additions beyond the copied core corpus. They pin
 * the wrapper against PHP's documented stream wrapper semantics (the
 * stream_open / stream_write / stream_lock contract in the PHP manual)
 * at the handle level: behaviors a caller reaches through fopen() on a
 * URI of the scheme under test, not through the file_system service.
 * Each test docblock marks it as an addition. Against the current
 * stub every leg goes red at the refused stream open, naming that
 * refusal, which is the awaited behavior.
 */
abstract class HandleSemanticsReplay extends FileReplayBase {

  /**
   * Tests stream_copy_to_stream() moving bytes down into the wrapper.
   *
   * Addition beyond the copied corpus: PHP documents stream-copy as
   * plain reads on the source handle plus writes on the target handle;
   * a bound wrapper that answers stream_open for a write mode must
   * then answer stream_write for every byte copied.
   */
  public function testStreamCopyToStreamDownloadsThroughTheWrapper(): void {
    $this->assertDispatchIsLive();
    $contents = str_repeat('d', 1000);

    // Source: a real temporary file on core's own wrapper (works today).
    $temp_uri = 'temporary://' . $this->randomMachineName() . '.bin';
    $written = file_put_contents($temp_uri, $contents);
    $this->assertNotFalse($written, 'Fixture bytes written on the temporary wrapper.');
    $source = fopen($temp_uri, 'r');
    $this->assertNotFalse($source, 'Fixture file opened for reading.');

    // Target: a file under the scheme under test.
    $target = fopen($this->uri('incoming.bin'), 'w');
    $this->assertNotFalse($target, 'The wrapper must accept stream_open() in write mode for the copy target.');

    $copied = stream_copy_to_stream($source, $target);
    $this->assertGreaterThan(0, $copied, 'Byte count must flow through the wrapper while copying.');

    fclose($source);
    $this->assertTrue(fclose($target), 'Closing the target handle must succeed.');
    $this->assertSame($contents, file_get_contents($this->uri('incoming.bin')), 'Reading the file back through the wrapper must return the copied bytes.');
  }

  /**
   * Tests flock() through the wrapper.
   *
   * Addition beyond the copied corpus: PHP documents stream_lock() as
   * the wrapper's answer to flock() on an open handle; an exclusive
   * lock and its release must both succeed on a file the wrapper just
   * opened for writing.
   */
  public function testFlockThroughTheWrapper(): void {
    $this->assertDispatchIsLive();
    $handle = fopen($this->uri('locked.txt'), 'w');
    $this->assertNotFalse($handle, 'The wrapper must accept stream_open() in write mode before locking can be tested.');
    $this->assertTrue(flock($handle, LOCK_EX), 'An exclusive lock acquired through the wrapper must succeed.');
    $this->assertTrue(flock($handle, LOCK_UN), 'Releasing that lock through the wrapper must succeed.');
    $this->assertTrue(fclose($handle), 'Closing the locked file must succeed.');
  }

  /**
   * Tests a partial mid-handle rewrite opened r+.
   *
   * Addition beyond the copied corpus: PHP documents read-plus-write
   * mode as positioning the pointer for both directions, with seek
   * answered by stream_seek() and writes overwriting at the pointer.
   * Writing 'XY' at offset 2 of 'abcdef' must yield 'abXYef' with the
   * remaining bytes intact.
   */
  // phpcs:ignore Drupal.NamingConventions.ValidFunctionName.ScopeNotCamelCaps -- Chosen name for the addition.
  public function testMidHandlePartialRewriteWithRPlus(): void {
    $this->assertDispatchIsLive();
    $written = file_put_contents($this->uri('r-plus.txt'), 'abcdef');
    $this->assertNotFalse($written, 'Initial content must be written through the wrapper.');

    $handle = fopen($this->uri('r-plus.txt'), 'r+');
    $this->assertNotFalse($handle, 'The wrapper must accept stream_open() in read-plus-write mode.');
    $this->assertSame(0, fseek($handle, 2), 'Seeking to the middle of the handle must succeed.');
    $this->assertSame(2, ftell($handle), 'The pointer must be at the sought offset.');
    $this->assertSame(2, fwrite($handle, 'XY'), 'The overwrite write must report its full length.');
    $this->assertTrue(fclose($handle), 'Closing the handle must succeed.');

    $this->assertSame('abXYef', file_get_contents($this->uri('r-plus.txt')), 'The rewritten file must read back with the tail bytes intact.');
  }

}
