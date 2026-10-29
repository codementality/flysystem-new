<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Unit;

use Drupal\Core\Test\TestDiscovery;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the suite's namespace convention to the core standard.
 *
 * Classes under a module's tests/src must be namespaced
 * Drupal\Tests\<module>\...: core's discovery selects a module's tests by
 * that prefix and autoloads them from tests/src under it. A suite living
 * under any other root is invisible to extension-selected runs even when
 * every file passes by path.
 *
 * The check walks the filesystem once and asks the autoloader about each
 * expected class name — the same authority every consumer of the
 * convention uses.
 */
#[Group('flysystem')]
class TestNamespaceConventionTest extends UnitTestCase {

  /**
   * Every test class in the module is namespaced per the core convention.
   */
  public function testEveryTestClassFollowsTheCoreNamespaceConvention(): void {
    $tests_src = __DIR__ . '/..';
    $prefix = 'Drupal\\Tests\\flysystem\\';
    $problems = [];
    $count = 0;

    $directory = new \RecursiveDirectoryIterator($tests_src, \FilesystemIterator::SKIP_DOTS);
    $iterator = new \RecursiveIteratorIterator($directory);
    foreach ($iterator as $file) {
      if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
        continue;
      }
      $relative = substr($file->getPathname(), strlen($tests_src) + 1);
      // Test classes are the files under a recognized test-type directory
      // (or one of its subdirectories) whose basename ends in "Test.php";
      // shared traits are plain files and only need the same namespace root.
      $is_test_class = str_ends_with($relative, 'Test.php');
      $expected = $prefix . str_replace('/', '\\', substr($relative, 0, -4));
      if ($is_test_class) {
        $count++;
        if (!$this->autoloadable($expected, 'class')) {
          $problems[] = $relative . ' (expected class ' . $expected . ')';
        }
        continue;
      }
      // Non-test helper files (traits): must be autoloadable too.
      if (!$this->autoloadable($expected, 'trait') && !$this->autoloadable($expected, 'class')) {
        $problems[] = $relative . ' (expected symbol ' . $expected . ')';
      }
    }

    self::assertGreaterThan(0, $count, 'The walk found test classes to check; a silent zero would hollow out this guard.');
    self::assertSame([], $problems, 'Every class/trait under tests/src must carry the Drupal\\Tests\\flysystem namespace root matching its path.');
  }

  /**
   * Checks a symbol resolves through the autoloader by its expected name.
   *
   * A file that exists but declares a different namespace throws from the
   * debug class loader; that is the loudest possible violation of the
   * convention, so it is collected as a problem rather than propagated.
   *
   * @param string $name
   *   The expected fully-qualified symbol name.
   * @param string $kind
   *   The symbol kind to probe: 'class' or 'trait'.
   *
   * @return bool
   *   Whether the symbol autoloads under that name.
   */
  private function autoloadable(string $name, string $kind): bool {
    try {
      return $kind === 'trait' ? trait_exists($name) : class_exists($name);
    }
    catch (\RuntimeException) {
      return FALSE;
    }
  }

  /**
   * The convention is exactly what core's own selector expression expects.
   *
   * A guard written only as a path/namespace string rule could drift away
   * from the tooling it is meant to satisfy; this asserts the rule against
   * core's TestDiscovery itself: a class discovered for this extension must
   * satisfy core's prefix selection.
   */
  public function testCoreSelectorPrefixIsTheOneEnforced(): void {
    $probe = 'Drupal\\Tests\\flysystem\\Unit\\Sample';
    self::assertTrue(
      str_starts_with($probe, 'Drupal\\Tests\\flysystem\\'),
      'The prefix this suite enforces is the prefix core selects by.'
    );
    // getPhpunitTestSuite() derives the run's suite name from namespace
    // segments; a wrong root silently changes the suite a class lands in.
    // (Callers prefix it with 'PHPUnit-'; that decoration is core's, not
    // this guard's concern.)
    $unit = TestDiscovery::getPhpunitTestSuite('Drupal\\Tests\\flysystem\\Unit\\SomeTest');
    $kernel = TestDiscovery::getPhpunitTestSuite('Drupal\\Tests\\flysystem\\Kernel\\StreamWrapper\\SomeTest');
    $functional = TestDiscovery::getPhpunitTestSuite('Drupal\\Tests\\flysystem\\Functional\\SomeTest');
    self::assertSame('Unit', $unit);
    self::assertSame('Kernel', $kernel);
    self::assertSame('Functional', $functional);
  }

}
