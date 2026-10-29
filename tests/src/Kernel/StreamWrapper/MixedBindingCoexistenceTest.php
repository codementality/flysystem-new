<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Kernel\StreamWrapper;

use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\Core\StreamWrapper\TemporaryStream;
use Drupal\flysystem\Factory\OperatorFactory;
use Drupal\flysystem\StreamWrapper\FlysystemPrivateStream;
use Drupal\flysystem\StreamWrapper\FlysystemPublicStream;
use Drupal\flysystem\StreamWrapper\FlysystemTemporaryStream;
use Drupal\Tests\flysystem\Traits\FlysystemBootTestTrait;
use Drupal\Tests\flysystem\Traits\FlysystemKernelTestTrait;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The coexistence matrix: every binding combination, three schemes.
 *
 * Each of public, private, and temporary may be bound or left to core,
 * and every one of the eight combinations must behave exactly as the
 * coexistence model predicts, independently per scheme: a bound scheme
 * dispatches to this module's wrapper and its storage, an unbound scheme
 * keeps core's class, core's location, and core's answers — no scheme's
 * binding state may leak into another. Rebinding a scheme between
 * deploys relocates the meaning of scheme-relative URIs without touching
 * any bytes: files written under one root become invisible (not
 * corrupted, not migrated) to the next root, and URLs keep answering from
 * the scheme, never from whichever folder currently stores it. Under all
 * of this sits exactly one resolution implementation: the wrapper asks
 * the storage factory which storage serves its scheme and obeys the
 * answer, so no second decision path below the resolver can disagree
 * with the first.
 */
#[Group('flysystem')]
#[RunTestsInSeparateProcesses]
final class MixedBindingCoexistenceTest extends KernelTestBase {

  use FlysystemKernelTestTrait;
  use FlysystemBootTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['flysystem'];

  /**
   * The webroot-relative scratch roots the matrix binds schemes into.
   *
   * @var array<string, string>
   */
  private array $roots = [];

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach ($this->roots as $root) {
      $this->removeScratchRoot(DRUPAL_ROOT . '/' . $root);
    }
    $this->roots = [];
    parent::tearDown();
  }

  /**
   * A fresh unique scratch root for a scheme binding.
   *
   * @param string $label
   *   Names the purpose inside the folder.
   *
   * @return string
   *   The webroot-relative path used as the binding's root.
   */
  private function scratchRoot(string $label): string {
    $root = 'tmp-flysystem-m2-07-' . $label . '-' . bin2hex(random_bytes(3));
    $this->createScratchRoot(DRUPAL_ROOT . '/' . $root);
    $this->roots[$label] = $root;
    return $root;
  }

  /**
   * A local binding for a scheme, pointing at a fresh scratch root.
   *
   * @param string $label
   *   Names the scratch folder.
   *
   * @return array
   *   A valid local binding.
   */
  private function bind(string $label): array {
    return ['driver' => 'local', 'config' => ['root' => $this->scratchRoot($label)]];
  }

  /**
   * All eight bound/unbound combinations answer identity per scheme.
   *
   * @param string[] $bound
   *   The schemes to bind in this combination.
   */
  #[DataProvider('combinationProvider')]
  public function testEachCombinationIdentifiesEverySchemeIndependently(array $bound): void {
    $bindings = [];
    foreach ($bound as $scheme) {
      $bindings[$scheme] = $this->bind($scheme);
    }
    $this->rebuildWithFlysystemBindings($bindings);
    $manager = $this->container->get('stream_wrapper_manager');

    $expected = [
      'public' => in_array('public', $bound, TRUE) ? FlysystemPublicStream::class : PublicStream::class,
      'temporary' => in_array('temporary', $bound, TRUE) ? FlysystemTemporaryStream::class : TemporaryStream::class,
    ];
    foreach ($expected as $scheme => $class) {
      self::assertSame($class, $manager->getClass($scheme), "Scheme {$scheme} in combination [" . implode('+', $bound) . "] must dispatch to {$class}.");
    }
    self::assertSame(in_array('private', $bound, TRUE) ? FlysystemPrivateStream::class : FALSE, $manager->getClass('private'), 'Private appears (bound) or stays absent exactly as core leaves it (no private path, no wrapper), never introduced by another scheme binding.');

    self::assertTrue(in_array('public', stream_get_wrappers(), TRUE));
    self::assertTrue(in_array('temporary', stream_get_wrappers(), TRUE));
    if (in_array('private', $bound, TRUE)) {
      self::assertTrue(in_array('private', stream_get_wrappers(), TRUE), 'A bound private scheme must register with PHP even where core defines none.');
    }
    else {
      self::assertFalse($manager->getClass('private'), 'With no binding and no core private path, the private scheme must stay absent — binding another scheme must not introduce it.');
    }
  }

  /**
   * The eight combinations of three schemes bound or not.
   *
   * @return array<string, array{0: string[]}>
   *   Named data sets, each a list of bound schemes.
   */
  public static function combinationProvider(): array {
    return [
      'none bound' => [[]],
      'public only' => [['public']],
      'private only' => [['private']],
      'temporary only' => [['temporary']],
      'public + private' => [['public', 'private']],
      'public + temporary' => [['public', 'temporary']],
      'private + temporary' => [['private', 'temporary']],
      'all three bound' => [['public', 'private', 'temporary']],
    ];
  }

  /**
   * Bound and unbound storage coexist in the same request, side by side.
   *
   * One boot binds public (to a scratch root) and leaves temporary
   * core-native: writes must physically separate — the bound folder holds
   * only the public file, and the core-native temporary location is
   * outside the bound root entirely.
   */
  public function testStorageLocationsStaySeparatedPerScheme(): void {
    $publicRoot = $this->bind('public');
    $this->rebuildWithFlysystemBindings(['public' => $publicRoot]);

    self::assertNotFalse(file_put_contents('public://kept.txt', 'bound-bytes'));
    self::assertNotFalse(file_put_contents('temporary://drift.txt', 'native-bytes'));

    $publicReal = DRUPAL_ROOT . '/' . $publicRoot['config']['root'];
    self::assertFileExists($publicReal . '/kept.txt');
    self::assertFileDoesNotExist($publicReal . '/drift.txt', 'The core-native temporary write must not enter the bound root.');

    $tempReal = (string) $this->container->get('file_system')->realpath('temporary://drift.txt');
    self::assertStringStartsWith((string) (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()), $tempReal, 'The unbound temporary scheme keeps core location.');
    self::assertFileExists($tempReal);
  }

  /**
   * Rebinding relocates the meaning of URIs without touching bytes.
   *
   * The file written under root one becomes invisible — not deleted, not
   * migrated — once the root changes, while its bytes sit exactly where
   * they were written; new writes land in the new root; unbinding again
   * hides both. A file written under one binding must never be reachable
   * through a different root.
   */
  public function testRebindingRelocatesMeaningNotData(): void {
    $rootA = $this->bind('root-a');
    $this->rebuildWithFlysystemBindings(['public' => $rootA]);
    self::assertNotFalse(file_put_contents('public://shared.txt', 'under-a'));
    self::assertTrue(file_exists('public://shared.txt'));

    $rootB = $this->bind('root-b');
    $this->rebuildWithFlysystemBindings(['public' => $rootB]);
    self::assertFalse(file_exists('public://shared.txt'), 'The previous binding must become invisible after rebinding, never reachable cross-root.');

    $fileA = DRUPAL_ROOT . '/' . $rootA['config']['root'] . '/shared.txt';
    self::assertFileExists($fileA, 'The bytes are untouched by the rebinding — relocation changes meaning, not data.');
    self::assertSame('under-a', file_get_contents($fileA));

    self::assertNotFalse(file_put_contents('public://fresh.txt', 'under-b'));
    self::assertFileExists(DRUPAL_ROOT . '/' . $rootB['config']['root'] . '/fresh.txt', 'New writes follow the new meaning.');

    $this->rebuildWithFlysystemBindings([]);
    self::assertFalse(file_exists('public://shared.txt'), 'Neither former root leaks through the core-native default.');
    self::assertFalse(file_exists('public://fresh.txt'));
  }

  /**
   * URLs follow the scheme, never whichever folder currently backs it.
   *
   * The generated URL of the same public URI must be identical with no
   * binding at all, binding to one scratch root, and rebinding to
   * another — serving locations and rebinding are storage facts, and the
   * URL the product emits is a scheme fact.
   */
  public function testPublicUrlIsIndependentOfTheBoundRoot(): void {
    $uri = 'public://images/pic.png';

    $this->rebuildWithFlysystemBindings([]);
    $native = $this->container->get('file_url_generator')->generateString($uri);
    self::assertNotEmpty($native);

    $this->rebuildWithFlysystemBindings(['public' => $this->bind('url-a')]);
    $boundA = $this->container->get('file_url_generator')->generateString($uri);
    self::assertSame($native, $boundA, 'Binding public may not move the URL.');

    $this->rebuildWithFlysystemBindings(['public' => $this->bind('url-b')]);
    self::assertSame($native, $this->container->get('file_url_generator')->generateString($uri), 'Rebinding may not move the URL either.');
  }

  /**
   * The resolver is the single decision point beneath the wrapper.
   *
   * When the wrapper is registered (because the settings shape bound the
   * scheme at build time) but the resolution now answers core-native, the
   * wrapper must refuse — obeying the resolver's answer rather than
   * re-inspecting settings through a branch of its own. A second decision
   * path could disagree; there is no second path: a stand-in factory
   * fully controls what the live wrapper believes about its scheme.
   */
  public function testTheWrapperObeysTheResolverAndNothingElse(): void {
    $root = $this->bind('obey');
    $this->rebuildWithFlysystemBindings(['public' => $root]);
    $standIn = $this->createMock(OperatorFactory::class);
    $standIn->method('operatorFor')->with('public')->willReturn(NULL);
    $this->container->set('flysystem.operator_factory', $standIn);
    \Drupal::setContainer($this->container);

    $handle = @fopen('public://whatever.txt', 'r');
    self::assertFalse($handle, 'A resolver answer of core-native while this wrapper is registered must refuse the operation, not guess.');
    $stray = array_values(array_diff((array) (scandir(DRUPAL_ROOT . '/' . $root['config']['root']) ?: []), ['.', '..']));
    self::assertSame([], preg_grep('/whatever/', $stray) ?: [], 'The refused operation created nothing, anywhere.');
  }

}
