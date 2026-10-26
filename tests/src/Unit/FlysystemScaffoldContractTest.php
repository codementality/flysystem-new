<?php

declare(strict_types=1);

namespace Drupal\flysystem\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests the module scaffold contract: packaging and the wrapper guard.
 *
 * Asserts the scaffold packaging as data: machine name / namespace
 * `flysystem` / `Drupal\flysystem`, the module's own composer.json runtime
 * requires (league/flysystem ^3 + flysystem-local; memory stays a host
 * dev-only package), and the scope guard — the module contributes ZERO
 * services tagged `stream_wrapper` and no call path reaches
 * stream_wrapper_register().
 */
#[Group('flysystem')]
#[CoversNothing]
final class FlysystemScaffoldContractTest extends TestCase {

  /**
   * The module root, located from this file (tests/src/Unit -> module root).
   */
  protected string $moduleRoot;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->moduleRoot = dirname(__DIR__, 3);
  }

  /**
   * Verifies that the required scaffold files exist.
   */
  public function testScaffoldFilesExist(): void {
    foreach (['flysystem.info.yml', 'flysystem.services.yml', 'composer.json'] as $file) {
      self::assertFileExists($this->moduleRoot . '/' . $file, "Missing scaffold file: $file");
    }
  }

  /**
   * Verifies the info file declares a Drupal 11 module with the right name.
   */
  public function testInfoYmlDeclaresModuleIdentity(): void {
    $path = $this->moduleRoot . '/flysystem.info.yml';
    self::assertFileExists($path);
    $info = Yaml::parseFile($path);
    self::assertSame('module', $info['type'] ?? NULL, 'flysystem must be a module.');
    self::assertArrayHasKey('core_version_requirement', $info, 'The info file must declare a core version requirement.');
    self::assertStringContainsString('11', (string) $info['core_version_requirement'], 'M1 targets the vendored Drupal 11 core.');
  }

  /**
   * Verifies the module composer.json declares runtime requires only.
   */
  public function testComposerRuntimeRequires(): void {
    $path = $this->moduleRoot . '/composer.json';
    self::assertFileExists($path);
    $composer = json_decode((string) file_get_contents($path), TRUE);
    self::assertIsArray($composer, 'The module composer.json must be valid JSON.');

    $require = $composer['require'] ?? [];
    self::assertArrayHasKey('league/flysystem', $require, 'Runtime requires must include league/flysystem.');
    self::assertMatchesRegularExpression('/^(\^)?3(\.|$|\s)/', trim((string) $require['league/flysystem']), 'league/flysystem must be constrained to version 3.');
    self::assertArrayHasKey('league/flysystem-local', $require, 'Runtime requires must include league/flysystem-local (the module\'s built-in local backend).');
    self::assertArrayNotHasKey('league/flysystem-memory', $require, 'The memory adapter is never a runtime require; it is satisfied by the host project\'s require-dev.');

    $psr4 = $composer['autoload']['psr-4'] ?? [];
    self::assertArrayHasKey('Drupal\\flysystem\\', $psr4, 'Autoload must map the Drupal\flysystem namespace.');
    self::assertSame('src/', $psr4['Drupal\\flysystem\\'], 'The namespace must map to src/.');
  }

  /**
   * Verifies zero services are tagged `stream_wrapper`.
   */
  public function testZeroStreamWrapperTaggedServices(): void {
    $found = [];
    foreach ($this->servicesFiles() as $file) {
      $definition = Yaml::parseFile($file) ?: [];
      foreach (($definition['services'] ?? []) as $id => $service) {
        foreach ((array) ($service['tags'] ?? []) as $tag) {
          if (($tag['name'] ?? (is_string($tag) ? $tag : NULL)) === 'stream_wrapper') {
            $found[] = basename($file) . ":$id";
          }
        }
      }
    }
    self::assertSame([], $found, 'The module must contribute zero services tagged stream_wrapper.');
  }

  /**
   * Verifies no production code path can reach stream_wrapper_register().
   */
  public function testNoCallPathReachesStreamWrapperRegister(): void {
    $pattern = '/stream_wrapper_register\s*\(|\bStreamWrapper::register\s*\(|implements\s+[^{]*StreamWrapperInterface/';
    $hits = [];
    foreach ($this->productionPhpFiles() as $file) {
      if (preg_match($pattern, (string) file_get_contents($file), $match)) {
        $hits[] = str_replace($this->moduleRoot . '/', '', $file) . ': ' . $match[0];
      }
    }
    self::assertSame([], $hits, 'No call path may reach stream_wrapper_register() (found: ' . implode(', ', $hits) . ').');
  }

  /**
   * Returns every *.services.yml of the module, excluding tests and vendor.
   *
   * @return string[]
   *   Absolute file paths.
   */
  protected function servicesFiles(): array {
    return $this->scanModuleFiles(fn(string $path): bool => str_ends_with($path, '.services.yml'));
  }

  /**
   * Returns production PHP files (src/ + root-level module files).
   *
   * @return string[]
   *   Absolute file paths.
   */
  protected function productionPhpFiles(): array {
    return $this->scanModuleFiles(fn(string $path): bool => preg_match('/\.(php|module|install)$/', $path) === 1);
  }

  /**
   * Walks the module tree, skipping tests/ and vendor/, collecting matches.
   *
   * @param callable $matcher
   *   Receives an absolute path; returns TRUE to include it.
   *
   * @return string[]
   *   Absolute file paths.
   */
  protected function scanModuleFiles(callable $matcher): array {
    $files = [];
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveCallbackFilterIterator(
        new \RecursiveDirectoryIterator($this->moduleRoot, \FilesystemIterator::SKIP_DOTS),
        function (\SplFileInfo $current): bool {
          return !in_array($current->getFilename(), ['tests', 'vendor', '.git'], TRUE);
        },
      ),
    );
    foreach ($iterator as $file) {
      if ($file->isFile() && $matcher($file->getPathname())) {
        $files[] = $file->getPathname();
      }
    }
    return $files;
  }

}
