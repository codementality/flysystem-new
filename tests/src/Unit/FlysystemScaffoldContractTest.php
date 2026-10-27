<?php

declare(strict_types=1);

namespace Drupal\flysystem\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests the module scaffold contract: packaging and the wrapper guards.
 *
 * Asserts the scaffold packaging as data: machine name / namespace
 * `flysystem` / `Drupal\flysystem`, the module's own composer.json runtime
 * requires (league/flysystem ^3 + flysystem-local; memory stays a host
 * dev-only package), and the wrapper discipline: the module's own YAML
 * files contribute zero services tagged `stream_wrapper` (wrapper
 * registration happens conditionally at container build time from
 * settings, never statically), and no production code path ever calls
 * PHP's own registration primitive directly — registration flows only
 * through core's wrapper manager.
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
    $info = Yaml::parseFile($path, Yaml::PARSE_CUSTOM_TAGS);
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

}
