<?php

declare(strict_types=1);

namespace Drupal\flysystem\Tests\Kernel\StreamWrapper;

use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\flysystem\Factory\OperatorFactory;
use Drupal\flysystem\StreamWrapper\FlysystemPublicStream;
use Drupal\Tests\flysystem\FlysystemBootTestTrait;
use Drupal\Tests\flysystem\FlysystemKernelTestTrait;
use Drupal\KernelTests\KernelTestBase;
use League\Flysystem\CorruptedPathDetected;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\PathTraversalDetected;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Pins the two-layer path discipline on a booted container.
 *
 * Two independent normalizers guard every path. Core's consumer-side
 * normalizeUri() branches on the registered wrapper class's LocalStream
 * lineage; this module's wrappers deliberately sit outside that lineage,
 * so bound schemes take the NON-LOCAL branch: interior '..' collapses
 * root-relative, a leading '..' pops nothing (silently root-relative,
 * never an escape), backslashes are not converted. The library's
 * operator-side normalizer runs before any backend sees a path: interior
 * '..' resolves within the bound root, a leading escape THROWS, and
 * control characters fail outright — a strictness gain over core, whose
 * pass-through would hand them to the filesystem. This suite asserts both
 * layers, raw and percent-encoded shapes, the root as the exact scheme
 * boundary, and working-directory determinism through the seam.
 */
#[Group('flysystem')]
#[RunTestsInSeparateProcesses]
final class TraversalMatrixTest extends KernelTestBase {

  use FlysystemKernelTestTrait;
  use FlysystemBootTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['flysystem'];

  /**
   * The real folder a bound scheme serves.
   *
   * @var string
   */
  protected string $root;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Inside the webroot: binding the publicly accessible public scheme
    // to a local root that lives outside the docroot would be refused by
    // the containment rule, which is correct behavior, not the subject
    // of this suite.
    $this->root = \Drupal::root() . '/sites/default/files/m2-04-' . getmypid();
    $this->createScratchRoot($this->root);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->removeScratchRoot($this->root);
    chdir(\Drupal::root());
    parent::tearDown();
  }

  /**
   * Binds public:// to the scratch root and returns its operator.
   */
  protected function bindAndOperator(?string $root = NULL): FilesystemOperator {
    $this->rebuildWithFlysystemBindings([
      'public' => ['driver' => 'local', 'config' => ['root' => $root ?? $this->root]],
    ]);
    $manager = $this->container->get('stream_wrapper_manager');
    self::assertSame(FlysystemPublicStream::class, $manager->getClass('public'));
    /** @var \Drupal\flysystem\Factory\OperatorFactory $factory */
    $factory = $this->container->get('flysystem.operator_factory');
    $operator = $factory->operatorFor('public');
    self::assertInstanceOf(FilesystemOperator::class, $operator);
    return $operator;
  }

  /**
   * A bound scheme takes core's non-local normalizeUri branch.
   *
   * The lineage fact in both directions, using the behavior that does not
   * depend on the host's directory separator: on the same manager, an
   * unbound scheme (core's own wrapper class, a LocalStream descendant)
   * PRESERVES a leading '..' — the legacy escape the containment rule
   * exists to stop. Once bound, the class is off the LocalStream lineage
   * and the same call collapses a leading '..' to root-relative, never
   * letting it escape; an interior '..' resolves against the bound root.
   */
  public function testLineageBranchChangesWithBinding(): void {
    $manager = $this->container->get('stream_wrapper_manager');
    $manager->register();

    self::assertSame(PublicStream::class, $manager->getClass('public'), 'unbound starts core-native');
    self::assertSame('public://../escape.txt', $manager->normalizeUri('public://../escape.txt'), 'the local branch PRESERVES a leading escape — the legacy quirk the deviation records');

    $this->bindAndOperator();
    // The bound answer lives on the rebuilt container's manager; the
    // class map is per-instance, exactly as the compile-time pass builds
    // it.
    $manager = $this->container->get('stream_wrapper_manager');

    self::assertSame(FlysystemPublicStream::class, $manager->getClass('public'));
    self::assertSame('public://escape.txt', $manager->normalizeUri('public://../escape.txt'), 'the non-local branch collapses a leading dot-dot: it pops nothing at the root, it never escapes');
    self::assertSame('public://b.txt', $manager->normalizeUri('public://sub/../b.txt'), 'interior dot-dot resolves root-relative');
    self::assertSame('public://sub/b.txt', $manager->normalizeUri('public://sub/deep/../b.txt'), 'each dot-dot pops exactly one segment');
  }

  /**
   * The library converts backslashes to separators on every platform.
   *
   * Core's local branch converts only when the host separator IS a
   * backslash (its str_replace uses DIRECTORY_SEPARATOR — a no-op on
   * Linux), while the library's normalizer unconditionally maps '\' to
   * '/'. A bound scheme follows the library: 'dir\name' lands one level
   * deeper, which is a documented deviation shape from today's Linux
   * behavior where such a name would stay one literal file.
   */
  public function testBackslashHandlingFollowsTheLibrary(): void {
    $operator = $this->bindAndOperator();

    $operator->write('under\over.txt', 'SPLIT');
    self::assertSame('SPLIT', $operator->read('under/over.txt'), 'a backslash acts as a separator through the operator');
    self::assertFileExists($this->root . '/under/over.txt');
  }

  /**
   * The library guard runs on every operator call: the root is the wall.
   */
  public function testOperatorGuardEngagesAtEveryCall(): void {
    $operator = $this->bindAndOperator();
    $operator->write('sub/inside.txt', 'INSIDE');

    self::assertSame('INSIDE', $operator->read('sub/../sub/inside.txt'), 'interior dot-dot resolves within the bound root');

    $this->expectException(PathTraversalDetected::class);
    $operator->write('../escaped.txt', 'X');
  }

  /**
   * Control characters fail at the guard — the strictness gain over core.
   */
  public function testControlCharactersFailNotPassThrough(): void {
    $operator = $this->bindAndOperator();

    // Core's own normalizer would pass control characters straight
    // through to the filesystem; the library's normalizer rejects them.
    $this->expectException(CorruptedPathDetected::class);
    $operator->write("bad\x07name.txt", 'X');
  }

  /**
   * Percent-encoded separators stay literal — never pre-decoded.
   *
   * The target extraction at the seam decodes nothing and the guard runs
   * on the literal characters, so an encoded traversal attempt creates a
   * file literally named with those percent signs and never moves
   * through directories.
   */
  public function testEncodedFormsStayLiteral(): void {
    $operator = $this->bindAndOperator();

    $operator->write('a/%2e%2e/b.txt', 'LITERAL');

    self::assertSame('LITERAL', $operator->read('a/%2e%2e/b.txt'));
    self::assertDirectoryExists($this->root . '/a/%2e%2e', 'the encoded name is a real, literal directory — no traversal was performed');
    self::assertFileDoesNotExist($this->root . '/../b.txt', 'nothing escaped the root');
  }

  /**
   * The configured root IS the scheme boundary for every shape of name.
   */
  public function testRootIsTheExactBoundary(): void {
    $operator = $this->bindAndOperator();

    $operator->write('one/two/three.txt', 'DEEP');
    $listed = [];
    foreach ($operator->listContents('', TRUE) as $attribute) {
      $listed[] = $attribute->path();
    }
    self::assertContains('one/two/three.txt', $listed, 'listings are root-relative: the prefix defines the namespace');

    self::assertFileExists($this->root . '/one/two/three.txt', 'the physical landing spot is root-joined target, exactly');
    self::assertStringStartsWith($this->root, realpath($this->root . '/one/two/three.txt'));
  }

  /**
   * The same relative-root URI resolves identically across CWD changes.
   *
   * A configured relative root anchors against the app root at binding
   * time; a CLI, cron, or web context must see the same storage. The
   * process working directory is moved after the operator exists and
   * again before a fresh resolution, and the bytes still answer from the
   * same physical file.
   */
  public function testRelativeRootAnchorsAcrossWorkingDirectories(): void {
    $appRoot = \Drupal::root();
    $relative = 'm2-04-anchor-probe';
    $absolute = $appRoot . '/' . $relative;
    $this->createScratchRoot($absolute);
    try {
      $this->setSetting('flysystem', [
        'public' => ['driver' => 'local', 'config' => ['root' => $relative]],
      ]);
      $kernel = $this->container->get('kernel');
      $this->container = $kernel->rebuildContainer();
      \Drupal::setContainer($this->container);

      $factory = $this->container->get('flysystem.operator_factory');
      assert($factory instanceof OperatorFactory);
      $fs = $factory->operatorFor('public');
      self::assertInstanceOf(FilesystemOperator::class, $fs);
      $fs->write('anchor.txt', 'ANCHORED');

      $cwd = getcwd();
      chdir(sys_get_temp_dir());
      try {
        self::assertSame('ANCHORED', $fs->read('anchor.txt'), 'a CWD change mid-request does not move the storage');
        self::assertFileExists($absolute . '/anchor.txt', 'bytes landed at the app-root-anchored location');
        self::assertFileDoesNotExist(sys_get_temp_dir() . '/anchor.txt', 'and never at a CWD-relative location');
      }
      finally {
        chdir((string) $cwd);
      }
    }
    finally {
      @unlink($absolute . '/anchor.txt');
      @rmdir($absolute);
    }
  }

  /**
   * A bound scheme's normalized URI is what the guard then enforces.
   *
   * Whatever shape a caller hands — raw with interior '..', or the form
   * core's consumer-side normalization already collapsed — the operator
   * reads the SAME file. The guard lives below both layers, so the two
   * cannot disagree about where a path resolves, and neither layer can
   * turn a resolved-in-root path into an escape.
   */
  public function testLayersComposeConsistently(): void {
    $operator = $this->bindAndOperator();
    $manager = $this->container->get('stream_wrapper_manager');
    $operator->write('sub/deep.txt', 'DEEP');

    $raw = 'sub/../sub/deep.txt';
    $normalized = (string) $manager->normalizeUri('public://' . $raw);
    self::assertSame('public://sub/deep.txt', $normalized, 'consumer-side normalization collapses the interior dot-dot to root-relative');
    $collapsed = StreamWrapperManager::getTarget($normalized);
    $target = StreamWrapperManager::getTarget('public://' . $raw);

    self::assertSame('DEEP', $operator->read($target), 'the guard resolves the raw form within the root');
    self::assertSame('DEEP', $operator->read($collapsed), 'the collapsed form reaches the same bytes');
    self::assertSame($operator->read($target), $operator->read($collapsed), 'both layers land on one answer');
  }

}
