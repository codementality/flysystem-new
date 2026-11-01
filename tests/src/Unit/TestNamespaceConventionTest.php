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
  public function testAllTestFilesUseTheCoreStandardRootAndMatchTheirPaths(): void {
    $testsDir = dirname(__DIR__);
    $expectedRoot = 'Drupal\\Tests\\flysystem';
    $problems = [];

    $files = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($testsDir, \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($files as $file) {
      if ($file->getExtension() !== 'php' || !str_ends_with($file->getFilename(), 'Test.php')) {
        continue;
      }
      $source = file_get_contents($file->getPathname());
      if (!preg_match('/^namespace ([^;]+);/m', $source, $match)) {
        $problems[] = $file->getFilename() . ': no namespace declaration';
        continue;
      }
      $namespace = $match[1];
      if (!str_starts_with($namespace, $expectedRoot)) {
        $problems[] = $file->getFilename() . ": root '$namespace'";
        continue;
      }
      $relative = substr(
        dirname($file->getPathname()),
        strlen($testsDir) + 1
      );
      $expectedNamespace = $expectedRoot . ($relative === '' ? '' : '\\' . str_replace('/', '\\', $relative));
      if ($namespace !== $expectedNamespace) {
        $problems[] = $file->getFilename() . ": '$namespace' != '$expectedNamespace' (path)";
      }
    }

    $this->assertSame([], $problems, 'Test namespaces must use the core-standard root and mirror file paths.');
  }

}
