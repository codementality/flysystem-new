<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Enforces the suite-wide test-root convention as data.
 *
 * Every test class lives under the core-standard root
 * Drupal\Tests\flysystem and its namespace matches its path: a file at
 * tests/src/Unit/Foo/BarTest.php declares
 * Drupal\Tests\flysystem\Unit\Foo\BarTest. Drift breaks core's own
 * suite selection, which maps test directories to roots by these
 * namespaces, so it is treated as a build failure rather than style.
 */
#[Group('flysystem')]
class TestNamespaceConventionTest extends UnitTestCase {

  /**
   * Asserts every test file's namespace root and path correspondence.
   */
  public function testNamespacesUseTheCoreStandardRootAndMirrorPaths(): void {
    $testsDir = dirname(__DIR__);
    $expectedRoot = 'Drupal\\Tests\\flysystem';
    $problems = [];

    $files = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($testsDir, \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($files as $file) {
      $filename = $file->getFilename();
      if ($file->getExtension() !== 'php'
        || !str_ends_with($filename, 'Test.php')) {
        continue;
      }
      $source = file_get_contents($file->getPathname());
      if (!preg_match('/^namespace ([^;]+);/m', $source, $match)) {
        $problems[] = $filename . ': no namespace declaration';
        continue;
      }
      $namespace = $match[1];
      if (!str_starts_with($namespace, $expectedRoot)) {
        $problems[] = $filename . ": root '$namespace'";
        continue;
      }
      $relative = substr(
        dirname($file->getPathname()),
        strlen($testsDir) + 1
      );
      $sub = $relative === '' ? '' : '\\' . str_replace('/', '\\', $relative);
      $expectedNamespace = $expectedRoot . $sub;
      if ($namespace !== $expectedNamespace) {
        $problems[] = $filename
          . ": '$namespace' != '$expectedNamespace' (path)";
      }
    }

    $this->assertSame(
      [],
      $problems,
      'Test namespaces must use the core-standard root and mirror file paths.'
    );
  }

}
