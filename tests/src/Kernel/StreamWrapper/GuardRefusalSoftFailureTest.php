<?php

declare(strict_types=1);

namespace Drupal\flysystem\Tests\Kernel\StreamWrapper;

use Drupal\flysystem\StreamWrapper\FlysystemPublicStream;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that refused paths answer as failed operations, never fatals.
 *
 * The storage guard rejects a path before the backend sees it: an escape
 * above the bound root, a control character in a name. Those rejections
 * are exceptions inside the library, but a site must not crash over a
 * hostile filename — PHP's own filesystem functions signal failure with
 * a false answer, and the wrapper must too, on every surface a file
 * operation can reach. The escape paths here are deliberately raw: no
 * caller-side normalization rescues them before the guard sees them.
 */
#[Group('flysystem')]
#[RunTestsInSeparateProcesses]
final class GuardRefusalSoftFailureTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['flysystem'];

  /**
   * The bound root, inside the web folder.
   *
   * @var string
   */
  protected string $root;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->root = \Drupal::root() . '/sites/default/files/m2-03-soft-' . getmypid();
    mkdir($this->root, 0777, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->rrmdir($this->root);
    parent::tearDown();
  }

  /**
   * Best-effort recursive cleanup.
   */
  private function rrmdir(string $dir): void {
    if (!is_dir($dir)) {
      return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
      if ($entry === '.' || $entry === '..') {
        continue;
      }
      $path = $dir . '/' . $entry;
      is_dir($path) ? $this->rrmdir($path) : @unlink($path);
    }
    @rmdir($dir);
  }

  /**
   * Binds public:// to the scratch root.
   */
  protected function bind(): void {
    $this->setSetting('flysystem', [
      'public' => ['driver' => 'local', 'config' => ['root' => $this->root]],
    ]);
    $kernel = $this->container->get('kernel');
    $this->container = $kernel->rebuildContainer();
    \Drupal::setContainer($this->container);
    $manager = $this->container->get('stream_wrapper_manager');
    $manager->register();
    self::assertSame(FlysystemPublicStream::class, $manager->getClass('public'));
  }

  /**
   * Hostile paths answer every existence-shaped question with "no".
   *
   * @param string $uri
   *   The scheme URI carrying a refused shape.
   * @param string $reason
   *   Which refusal, for the failure message.
   */
  #[DataProvider('refusedPaths')]
  public function testExistenceQuestionsAnswerNo(string $uri, string $reason): void {
    $this->bind();

    self::assertFalse(@lstat($uri), $reason . ': lstat must fail softly');
    self::assertFalse(@file_exists($uri), $reason . ': file_exists must answer no');
    self::assertFalse(@is_file($uri), $reason . ': is_file must answer no');
    self::assertFalse(@is_dir($uri), $reason . ': is_dir must answer no');
    self::assertFalse(@filesize($uri), $reason . ': filesize must fail softly');
    self::assertFalse(@filemtime($uri), $reason . ': filemtime must fail softly');
  }

  /**
   * Refused paths.
   */
  public static function refusedPaths(): array {
    return [
      'leading escape' => ['public://../sneaky.txt', 'a path above the bound root'],
      'control character' => ["public://bad\x07name.txt", 'a control character in a name'],
    ];
  }

  /**
   * Hostile mutation targets refuse without crashing or touching disk.
   *
   * The methods here are the metadata-surface operations (unlink, rename,
   * mkdir, rmdir, directory listing) — each one asks the operator a
   * question that the guard may refuse, so the refusal must answer as a
   * failed operation.
   */
  public function testMutationsRefuseWithoutFatal(): void {
    $this->bind();

    self::assertFalse(@unlink('public://../doomed.txt'), 'unlink of an escape fails softly');
    self::assertFalse(@rename('public://../from.txt', 'public://to.txt'), 'rename from an escape fails softly');
    self::assertFalse(@rename('public://ok.txt', 'public://../to.txt'), 'rename to an escape fails softly');
    self::assertFalse(@mkdir('public://../dir', 0775), 'mkdir of an escape fails softly');
    self::assertFalse(@rmdir('public://../dir'), 'rmdir of an escape fails softly');
    self::assertFalse(@scandir('public://../list'), 'listing an escape fails softly');
  }

  /**
   * The file_system service surface answers refusals as failed lookups.
   */
  public function testServiceLevelAnswersStaySoft(): void {
    $this->bind();
    /** @var \Drupal\Core\File\FileSystemInterface $filesystem */
    $filesystem = $this->container->get('file_system');

    self::assertFalse($filesystem->realpath('public://../x.txt'), 'the bound root is the wall: no canonical path above it');
    self::assertFalse($filesystem->realpath('public://deep/../../outside.txt'), 'a collapse that would exit the root answers as a failure');
  }

  /**
   * The root clamp keeps ordinary paths answering exactly.
   */
  public function testRootClampKeepsOrdinaryPaths(): void {
    $this->bind();
    /** @var \Drupal\Core\File\FileSystemInterface $filesystem */
    $filesystem = $this->container->get('file_system');
    self::assertSame(
      $this->root . '/deep/nest.txt',
      $filesystem->realpath('public://deep/nest.txt'),
      'a path inside the root still resolves',
    );
  }

}
