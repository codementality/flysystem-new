<?php

declare(strict_types=1);

namespace Drupal\flysystem\Tests\Kernel\StreamWrapper;

require_once __DIR__ . '/../../FlysystemBootTestTrait.php';
require_once __DIR__ . '/../../FlysystemKernelTestTrait.php';

use Drupal\flysystem\StreamWrapper\FlysystemPrivateStream;
use Drupal\flysystem\Tests\FlysystemBootTestTrait;
use Drupal\flysystem\Tests\FlysystemKernelTestTrait;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Replays the fopen-family byte semantics end-to-end through a bound scheme.
 *
 * The handle-semantics contract proven against a double in the units is
 * replayed here against a real local binding: the storage factory builds
 * a real operator over a real folder, PHP's own stream functions dispatch
 * through the registered wrapper, and the bytes must arrive in the bound
 * folder exactly as the file modes promise — write, append, partial
 * rewrite at a seek, exclusive create, whole-file helpers, and reads large
 * enough to cross buffer boundaries. The private scheme is used because a
 * private binding may live outside the web folder (the publicly served
 * rule belongs to the storage scheme being replaced, and the test root is
 * a scratch folder); the byte machinery is the same either way.
 */
#[Group('flysystem')]
#[RunTestsInSeparateProcesses]
final class WrapperHandleEndToEndTest extends KernelTestBase {

  use FlysystemKernelTestTrait;
  use FlysystemBootTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['flysystem'];

  /**
   * The real folder the bound scheme writes into.
   *
   * @var string
   */
  protected string $root;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->root = sys_get_temp_dir() . '/flysystem-m2-02-' . getmypid() . '-private';
    $this->createScratchRoot($this->root);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach (glob($this->root . '/*') ?: [] as $file) {
      @unlink((string) $file);
    }
    @rmdir($this->root);
    parent::tearDown();
  }

  /**
   * Binds private:// to the test root, making PHP dispatch through us.
   */
  protected function bindPrivate(): void {
    $this->rebuildWithFlysystemBindings([
      'private' => ['driver' => 'local', 'config' => ['root' => $this->root]],
    ]);
    self::assertSame(FlysystemPrivateStream::class, $this->container->get('stream_wrapper_manager')->getClass('private'), 'Proof of the seam: PHP now dispatches private:// through this module.');
  }

  /**
   * Write and read round-trip lands bytes in the bound folder.
   */
  public function testWriteReadRoundTripThroughPhpStreamFunctions(): void {
    $this->bindPrivate();

    self::assertNotFalse(file_put_contents('private://greeting.txt', 'hello world'));
    self::assertSame('hello world', file_get_contents('private://greeting.txt'));
    self::assertSame('hello world', file_get_contents($this->root . '/greeting.txt'), 'The bytes must live in the bound root, written through the operator, nowhere else.');

    $handle = fopen('private://greeting.txt', 'r');
    self::assertNotFalse($handle);
    self::assertSame('hello', fread($handle, 5));
    self::assertSame(' world', fread($handle, 4096));
    self::assertTrue(feof($handle));
    fclose($handle);
  }

  /**
   * Append adds at the end and preserves what was there.
   */
  public function testAppendPreservesExistingBytes(): void {
    $this->bindPrivate();
    file_put_contents('private://log.txt', 'first');

    $handle = fopen('private://log.txt', 'a');
    self::assertNotFalse($handle);
    self::assertSame(5, ftell($handle), 'Append starts at end of the existing content.');
    fwrite($handle, '-second');
    fclose($handle);

    self::assertSame('first-second', file_get_contents($this->root . '/log.txt'));
  }

  /**
   * Seek then rewrite patches mid-file and keeps the remainder.
   */
  public function testPartialRewriteAtSeekKeepsSurroundings(): void {
    $this->bindPrivate();
    file_put_contents('private://patch.txt', 'AAAAABBBBB');

    $handle = fopen('private://patch.txt', 'r+');
    self::assertNotFalse($handle);
    fseek($handle, 5);
    fwrite($handle, 'XXXXX');
    fclose($handle);

    self::assertSame('AAAAAXXXXX', file_get_contents($this->root . '/patch.txt'));
  }

  /**
   * Mode 'w' truncates an existing file.
   */
  public function testWriteModeTruncatesExisting(): void {
    $this->bindPrivate();
    file_put_contents('private://trunc.txt', 'old contents here');

    file_put_contents('private://trunc.txt', 'new');
    self::assertSame('new', file_get_contents($this->root . '/trunc.txt'));
  }

  /**
   * Exclusive create refuses an existing path, allows a fresh one.
   */
  public function testExclusiveModeSemantics(): void {
    $this->bindPrivate();
    file_put_contents('private://taken.txt', 'here');

    self::assertFalse(@fopen('private://taken.txt', 'x'), 'Exclusive mode must refuse an existing file.');
    $fresh = @fopen('private://fresh.txt', 'x');
    self::assertNotFalse($fresh);
    fwrite($fresh, 'born new');
    fclose($fresh);
    self::assertSame('born new', file_get_contents($this->root . '/fresh.txt'));
  }

  /**
   * Reads larger than one buffer chunk reassemble exactly.
   */
  public function testLargeContentReadsBackWhole(): void {
    $this->bindPrivate();
    $payload = str_repeat('0123456789abcdef', 65536);

    file_put_contents('private://big.bin', $payload);
    $read = file_get_contents('private://big.bin');
    self::assertSame(strlen($payload), strlen((string) $read));
    self::assertSame($payload, $read, 'A multi-megabyte file must survive the whole-file pull intact.');

    $handle = fopen('private://big.bin', 'r');
    fread($handle, 100);
    fseek($handle, 16, SEEK_CUR);
    // Position is now 116; the payload repeats a 16-byte block, so byte
    // 116 begins at offset 116 % 16 == 4 within the block.
    self::assertSame('456789abcdef0123', fread($handle, 16), 'Positioned reads inside a large file serve from the buffer like a real file.');
    fclose($handle);
  }

  /**
   * Reading a missing file fails without leaving a trace behind.
   */
  public function testMissingFileFailsCleanly(): void {
    $this->bindPrivate();

    self::assertFalse(@fopen('private://nowhere.txt', 'r'));
    self::assertFileDoesNotExist($this->root . '/nowhere.txt');
  }

  /**
   * Advisory locks are refused on bound handles — pinned deviation.
   *
   * A stock local wrapper flocks the real file handle; the buffer model
   * holds none during the life of the stream, and reaching for the file's
   * raw path behind the operator to lock it would bypass the guard the
   * scheme boundary depends on. Refusing is the documented behavior, so
   * a flock() call must fail rather than succeed by accident or hang:
   * callers that require lock protection keep using storage that provides
   * it. This deviation ships with the local column until a real demand
   * for handle-level locking appears.
   */
  public function testAdvisoryLockRefusedOnBoundHandle(): void {
    $this->bindPrivate();
    file_put_contents('private://locked.txt', 'content');

    $handle = fopen('private://locked.txt', 'r');
    self::assertNotFalse($handle);
    self::assertFalse(@flock($handle, LOCK_EX), 'Locking a bound handle must be refused, not emulated.');
    fclose($handle);
    self::assertSame('content', file_get_contents('private://locked.txt'), 'The refused lock attempt leaves the file untouched.');
  }

}
