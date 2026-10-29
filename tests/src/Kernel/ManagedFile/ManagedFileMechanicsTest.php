<?php

declare(strict_types=1);

namespace Drupal\flysystem\Tests\Kernel\ManagedFile;

use Drupal\Core\File\FileExists;
use Drupal\file\Entity\File;
use Drupal\Tests\flysystem\FlysystemBootTestTrait;
use Drupal\Tests\flysystem\FlysystemKernelTestTrait;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Replays core's managed-file mechanics against bound storage schemes.
 *
 * The product-level promise of the seam: the File entity layer, the file
 * system service, and image-style derivative generation all keep working
 * byte-for-byte as core-native when a scheme binds to local storage —
 * placement, the pre-save size stat, physical removal on delete, core's
 * ignore-and-continue posture when removal fails, recursive directory
 * operations, and the realpath answer the upload retry path consumes.
 * Managed URIs stay scheme-relative: nothing but the binding moves when
 * a scheme binds; stored strings and entity semantics are untouched.
 */
#[Group('flysystem')]
#[RunTestsInSeparateProcesses]
final class ManagedFileMechanicsTest extends KernelTestBase {

  use FlysystemKernelTestTrait;
  use FlysystemBootTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['flysystem', 'file', 'user'];

  /**
   * The configured relative root, inside the required webroot.
   *
   * Relative keeps the public binding inside the webroot the containment
   * rule requires.
   *
   * @var string
   */
  protected string $publicRoot;

  /**
   * The same root anchored at the app root, for real-path assertions.
   *
   * @var string
   */
  protected string $publicReal;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('file');
    $this->installEntitySchema('user');
    $this->installSchema('file', ['file_usage']);
    $this->publicRoot = 'tmp-flysystem-m2-06-' . getmypid() . '-public';
    $this->publicReal = $this->container->getParameter('app.root') . '/' . $this->publicRoot;
    $this->createScratchRoot($this->publicReal);
    $this->bindPublic();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->removeScratchRoot($this->publicReal);
    parent::tearDown();
  }

  /**
   * Points the public scheme at the scratch folder and registers.
   */
  private function bindPublic(): void {
    $this->rebuildWithFlysystemBindings([
      'public' => ['driver' => 'local', 'config' => ['root' => $this->publicRoot]],
    ]);
  }

  /**
   * The entity save cycle stats through the bound wrapper.
   *
   * The pre-save size question is a stat on the scheme — it must answer
   * from the bound storage, the stored URI must stay scheme-relative in
   * the database, and the entity layer must know nothing of the binding.
   */
  public function testEntitySaveStatsThroughTheWrapper(): void {
    self::assertNotFalse(file_put_contents('public://managed.txt', '1234567890'));

    $file = File::create(['uri' => 'public://managed.txt', 'uid' => 0]);
    $file->save();

    self::assertSame(10, (int) $file->getSize(), 'The pre-save size must answer from bound storage.');

    $stored_uri = $this->container->get('database')
      ->select('file_managed', 'f')
      ->fields('f', ['uri'])
      ->condition('fid', (int) $file->id())
      ->execute()
      ->fetchField();
    self::assertSame('public://managed.txt', $stored_uri, 'Managed URIs stay scheme-relative in storage; the binding never leaks into the entity.');
  }

  /**
   * Entity deletion physically removes the object from the bound root.
   */
  public function testEntityDeleteRemovesThePhysicalObject(): void {
    file_put_contents('public://doomed.txt', 'bytes');
    $file = File::create(['uri' => 'public://doomed.txt', 'uid' => 0]);
    $file->save();
    $id = (int) $file->id();

    $file->delete();

    self::assertNull(File::load($id), 'The entity row is gone.');
    self::assertFalse(file_exists('public://doomed.txt'), 'The wrapper reports the object removed.');
    self::assertFileDoesNotExist($this->publicReal . '/doomed.txt', 'The bytes are gone from the bound root, not just the listing.');
  }

  /**
   * When physical removal fails, core ignores and continues; so must we.
   *
   * The removal failure is injected at the seam itself, not through the
   * host filesystem: write through a good binding, then rebind the scheme
   * to a broken driver. Now the wrapper's unlink answers its recorded
   * refusal, core's delete wraps that in a file exception, and the entity
   * delete layer catches and ignores it. The promised posture is:
   * no fatal, entity row gone, the object that could not be removed stays
   * exactly where it is, nothing pretends the removal happened.
   */
  public function testFailedPhysicalRemovalIsIgnoredAndContinued(): void {
    file_put_contents('public://stuck.txt', 'held');
    $file = File::create(['uri' => 'public://stuck.txt', 'uid' => 0]);
    $file->save();
    $id = (int) $file->id();

    $this->setSetting('flysystem', [
      'public' => ['driver' => 'nosuchplugin'],
    ]);
    $kernel = $this->container->get('kernel');
    $this->container = $kernel->rebuildContainer();
    \Drupal::setContainer($this->container);
    $this->container->get('stream_wrapper_manager')->register();

    $file->delete();

    self::assertNull(File::load($id), 'The entity deletes even when the object cannot be removed.');
    self::assertFileExists($this->publicReal . '/stuck.txt', 'A refusal leaves bytes in place; nothing pretends they are gone.');
  }

  /**
   * The file system service mechanics all answer through the binding.
   *
   * Recursive mkdir, copy, unlink, and deleteRecursive are the exact
   * calls contrib uses; each must land inside the bound root and report
   * truthfully.
   */
  public function testFileSystemServiceMechanics(): void {
    $fs = $this->container->get('file_system');

    self::assertTrue($fs->mkdir('public://deep/nested/dir', 0775, TRUE));
    self::assertTrue(is_dir('public://deep/nested/dir'));
    self::assertTrue(is_dir($this->publicReal . '/deep/nested/dir'), 'The created tree is inside the bound root.');

    file_put_contents('public://source.txt', 'payload');
    $copied = $fs->copy('public://source.txt', 'public://deep/nested/copy.txt', FileExists::Replace);
    self::assertSame('public://deep/nested/copy.txt', $copied, 'The service answers the destination URI, the core-native shape.');
    self::assertSame('payload', file_get_contents('public://deep/nested/copy.txt'));
    self::assertSame('payload', file_get_contents($this->publicReal . '/deep/nested/copy.txt'));

    self::assertTrue($fs->unlink('public://source.txt'));
    self::assertFileDoesNotExist($this->publicReal . '/source.txt');

    self::assertTrue($fs->deleteRecursive('public://deep'));
    self::assertFalse(is_dir('public://deep'));
    self::assertDirectoryDoesNotExist($this->publicReal . '/deep');
  }

  /**
   * The realpath answer the upload retry consumes is the bound path.
   *
   * The upload handler's fallback branch retries against
   * file_system->realpath(); on a bound local scheme that must be the
   * root-joined real path — which is why local bindings pass the
   * placement on the current path. One documented deviation from
   * core-native lives here: the answer is lexical, so a missing target
   * still reports the path where it would be, while core's PHP realpath
   * answers FALSE. Upload placement parity holds because both retry
   * branches move the bytes to the same location.
   */
  public function testRealpathAnswersTheUploadRetryContract(): void {
    file_put_contents('public://upload-target.txt', 'data');
    $fs = $this->container->get('file_system');

    self::assertSame($this->publicReal . '/upload-target.txt', $fs->realpath('public://upload-target.txt'));
    self::assertSame($this->publicReal . '/nowhere.txt', $fs->realpath('public://nowhere.txt'), 'Lexical by design: the retry consumes the path where the object would live.');
  }

  /**
   * The 255-character URI budget is unproblematic on local bindings.
   *
   * A long scheme-relative URI well under the budget saves, stats, and
   * round-trips through the entity layer without truncation.
   */
  public function testLongUriBudgetIsComfortableLocally(): void {
    $uri = 'public://' . str_repeat('d', 100) . '/' . str_repeat('f', 140) . '.txt';
    self::assertLessThanOrEqual(255, strlen($uri), 'The fixture stays inside the URI budget.');

    self::assertNotFalse(file_put_contents($uri, 'long path payload'));
    self::assertSame('long path payload', file_get_contents($uri));

    $file = File::create(['uri' => $uri, 'uid' => 0]);
    $file->save();
    self::assertSame(17, (int) $file->getSize());

    $reloaded = File::load((int) $file->id());
    self::assertNotNull($reloaded);
    self::assertSame($uri, $reloaded->getFileUri(), 'The full long URI round-trips through the entity unchanged.');
  }

}
