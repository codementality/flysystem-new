<?php

declare(strict_types=1);

namespace Drupal\flysystem\Tests\Kernel\StreamWrapper;

use Drupal\flysystem\StreamWrapper\FlysystemPrivateStream;
use Drupal\Tests\flysystem\FlysystemBootTestTrait;
use Drupal\Tests\flysystem\FlysystemKernelTestTrait;
use Drupal\KernelTests\KernelTestBase;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Replays metadata and directory expectations through a bound scheme.
 *
 * PHP's file_* and directory functions dispatch through the registered
 * wrapper and must answer from the bound folder exactly as core's local
 * wrapper does: existence, size, modification time, the directory bit,
 * recursive mkdir, scandir contents, non-empty rmdir refusal, rename,
 * unlink, and touch. Setup and teardown put files in place through the
 * storage operator directly, so this row is proven independently of the
 * handle-semantics row it runs beside; every assertion, however, goes
 * through PHP's own functions against the bound scheme. The private
 * scheme is bound to a real temp folder (a private binding may live
 * outside the web folder; the machinery is identical for public).
 */
#[Group('flysystem')]
#[RunTestsInSeparateProcesses]
final class WrapperMetadataEndToEndTest extends KernelTestBase {

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
    $this->root = sys_get_temp_dir() . '/flysystem-m2-03-' . getmypid() . '-private';
    $this->createScratchRoot($this->root);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->removeScratchRoot($this->root);
    parent::tearDown();
  }

  /**
   * Binds private:// to the scratch root and proves dispatch arrives.
   */
  protected function bindPrivate(): FilesystemOperator {
    $this->rebuildWithFlysystemBindings([
      'private' => ['driver' => 'local', 'config' => ['root' => $this->root]],
    ]);
    self::assertSame(FlysystemPrivateStream::class, $this->container->get('stream_wrapper_manager')->getClass('private'));
    /** @var \Drupal\flysystem\Factory\OperatorFactory $factory */
    $factory = $this->container->get('flysystem.operator_factory');
    $operator = $factory->operatorFor('private');
    self::assertInstanceOf(FilesystemOperator::class, $operator);
    return $operator;
  }

  /**
   * Existence, size, type, and time answer through PHP's file functions.
   */
  public function testFileMetadataAnswers(): void {
    $operator = $this->bindPrivate();
    $operator->write('sized.txt', '1234567890');

    self::assertTrue(file_exists('private://sized.txt'));
    self::assertFalse(file_exists('private://missing.txt'));
    self::assertSame(10, filesize('private://sized.txt'));
    self::assertTrue(is_file('private://sized.txt'));
    self::assertFalse(is_dir('private://sized.txt'));

    $stat = stat('private://sized.txt');
    self::assertIsArray($stat);
    self::assertSame(10, $stat['size']);
    self::assertIsInt($stat['mtime']);
    self::assertGreaterThan(0, $stat['mode'] & 0100000);
    // No null anywhere in the array PHP reads.
    array_walk_recursive($stat, function ($value): void {
      self::assertNotNull($value);
    });
    // The mode's permission bits match what a locally written public file
    // carries (the site's file mode governs the write; the stat answers
    // with it).
    self::assertGreaterThan(0, $stat['mode'] & 0777);
  }

  /**
   * Modification time is real: a fresh write reads a recent mtime.
   */
  public function testModificationTimeIsCurrent(): void {
    $operator = $this->bindPrivate();
    $operator->write('timed.txt', 'first');

    $mtime = filemtime('private://timed.txt');
    self::assertIsInt($mtime);
    self::assertLessThanOrEqual(5, abs(time() - $mtime), 'A file written seconds ago reports a current mtime through the wrapper.');
  }

  /**
   * Directories report the directory bit through mkdir recursion.
   */
  public function testDirectoryCreationAndIdentity(): void {
    $this->bindPrivate();

    self::assertTrue(mkdir('private://gallery/thumbs', 0775, TRUE), 'Recursive mkdir through the wrapper creates the whole chain.');
    self::assertTrue(is_dir('private://gallery'));
    self::assertTrue(is_dir('private://gallery/thumbs'));
    self::assertDirectoryExists($this->root . '/gallery/thumbs', 'The folders exist in the bound folder on disk.');
    $stat = stat('private://gallery');
    self::assertGreaterThan(0, $stat[2] & 0040000);
    self::assertGreaterThan(0, $stat['mode'] & 0777, 'directory mode answers from the binding');
  }

  /**
   * Scandir lists the folder with dot entries then the children.
   */
  public function testScandirListsChildren(): void {
    $operator = $this->bindPrivate();
    $operator->createDirectory('shelf');
    $operator->write('shelf/b.txt', 'B');
    $operator->write('shelf/a.txt', 'A');
    $operator->createDirectory('shelf/nested');

    $listed = scandir('private://shelf');
    self::assertIsArray($listed);
    self::assertSame(['.', '..'], array_slice((array) $listed, 0, 2), 'dot entries lead the listing, exactly as through core\'s local wrapper');
    self::assertContains('a.txt', $listed);
    self::assertContains('b.txt', $listed);
    self::assertContains('nested', $listed, 'subdirectories appear in listings, as on a real folder');
    self::assertNotContains('c.txt', $listed);

    $sorted = scandir('private://shelf', SCANDIR_SORT_DESCENDING);
    self::assertGreaterThan(0, count((array) $sorted));
  }

  /**
   * Rmdir refuses a non-empty folder and allows an empty one.
   */
  public function testRmdirSemantics(): void {
    $operator = $this->bindPrivate();
    $operator->createDirectory('stuff');
    $operator->write('stuff/keep.txt', 'x');

    self::assertFalse(@rmdir('private://stuff'), 'A non-empty folder must refuse, as any real filesystem does.');
    self::assertTrue(is_dir('private://stuff'));
    self::assertFileExists($this->root . '/stuff/keep.txt', 'the refusal wiped nothing');

    self::assertTrue(unlink('private://stuff/keep.txt'));
    self::assertTrue(rmdir('private://stuff'));
    self::assertFalse(is_dir('private://stuff'));
    self::assertDirectoryDoesNotExist($this->root . '/stuff');
  }

  /**
   * Rename relocates the object; the old path stops answering.
   */
  public function testRenameMovesTheObject(): void {
    $operator = $this->bindPrivate();
    $operator->write('before.txt', 'payload');

    self::assertTrue(rename('private://before.txt', 'private://after.txt'));
    self::assertFalse(file_exists('private://before.txt'));
    self::assertSame('payload', (string) file_get_contents($this->root . '/after.txt'), 'bytes arrived through the native move');
    self::assertFileDoesNotExist($this->root . '/before.txt');
  }

  /**
   * Unlink removes the object from the bound folder.
   */
  public function testUnlinkRemoves(): void {
    $operator = $this->bindPrivate();
    $operator->write('doomed.txt', 'x');

    self::assertTrue(unlink('private://doomed.txt'));
    self::assertFalse(file_exists('private://doomed.txt'));
    self::assertFileDoesNotExist($this->root . '/doomed.txt');
    self::assertFalse(@unlink('private://doomed.txt'), 'unlinking a missing file fails, not no-ops true.');
  }

  /**
   * Touch moves mtime forward and preserves content.
   */
  public function testTouchMovesMtimeAndKeepsBytes(): void {
    $operator = $this->bindPrivate();
    $operator->write('touched.txt', 'same bytes');
    clearstatcache();
    $before = filemtime('private://touched.txt');

    sleep(2);
    self::assertTrue(touch('private://touched.txt'));
    clearstatcache();
    $after = filemtime('private://touched.txt');

    self::assertGreaterThan($before, $after, 'A touch moves mtime forward.');
    self::assertSame('same bytes', (string) file_get_contents($this->root . '/touched.txt'), 'Touch must not disturb content.');
  }

  /**
   * Touch creates a missing file empty.
   */
  public function testTouchCreatesMissingFile(): void {
    $this->bindPrivate();

    self::assertTrue(touch('private://born-by-touch.txt'));
    self::assertFileExists($this->root . '/born-by-touch.txt');
    self::assertSame(0, filesize('private://born-by-touch.txt'));
  }

  /**
   * Realpath resolves local storage through the wrapper's own contract.
   *
   * PHP's builtin realpath is not a stream hook; core's file_system
   * service delegates to the wrapper's realpath() for stream URIs, and
   * that is the consumer path a bound scheme answers.
   */
  public function testRealpathForLocalRoot(): void {
    $operator = $this->bindPrivate();
    $operator->write('deep/nest/here.txt', 'x');

    $resolved = \Drupal::service('file_system')->realpath('private://deep/nest/here.txt');
    self::assertSame($this->root . '/deep/nest/here.txt', $resolved, 'The canonical path is the binding root joined to the target — anchored lexically, no symlink chasing.');
  }

  /**
   * The whole metadata surface agrees after a rewrite cycle.
   */
  public function testRewriteCycleKeepsMetadataHonest(): void {
    $operator = $this->bindPrivate();
    $operator->write('cycle.txt', 'one');
    self::assertSame(3, filesize('private://cycle.txt'));

    self::assertTrue(unlink('private://cycle.txt'));
    self::assertFalse(file_exists('private://cycle.txt'));
    self::assertFalse(is_file('private://cycle.txt'));

    $operator->write('cycle.txt', 'two-three-four');
    self::assertTrue(file_exists('private://cycle.txt'));
    self::assertSame(14, filesize('private://cycle.txt'));
    self::assertGreaterThan(0, filemtime('private://cycle.txt'));
  }

  /**
   * Mode changes apply through the binding's permission model.
   *
   * Ownership changes still refuse.
   *
   * Core's managed-image pipeline chmods every derivative it writes, so
   * a mode a binding can express must answer for real, through the
   * operator's own visibility call — never through a rewrite of the
   * file's bytes. Owner-only modes answer as private, anything readable
   * beyond the owner as public. Ownership has no representation in the
   * abstraction and keeps refusing as a failed operation.
   */
  public function testChmodAppliesVisibilityAndChownStillRefuses(): void {
    $operator = $this->bindPrivate();
    $operator->write('guarded-modes.txt', 'payload');

    $realPath = $this->root . '/guarded-modes.txt';
    self::assertFileExists($realPath);

    self::assertTrue(@chmod('private://guarded-modes.txt', 0600), 'Owner-only mode answers through visibility.');
    clearstatcache(TRUE, $realPath);
    self::assertSame(0, fileperms($realPath) & 0044, 'The group/world read bits really moved away.');
    self::assertSame('payload', file_get_contents('private://guarded-modes.txt'), 'Visibility changed; bytes never moved.');

    self::assertTrue(@chmod('private://guarded-modes.txt', 0664), 'World-readable mode answers through visibility.');
    clearstatcache(TRUE, $realPath);
    self::assertNotSame(0, fileperms($realPath) & 0044, 'The read bits returned through the same real call.');

    self::assertFalse(@chown('private://guarded-modes.txt', 0), 'Ownership has no answer in the abstraction; PHP must see it fail.');
    self::assertSame('payload', file_get_contents('private://guarded-modes.txt'), 'The refused chown left everything untouched.');
  }

}
