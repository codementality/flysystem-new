<?php

declare(strict_types=1);

namespace Drupal\flysystem\Tests\Unit\Definition;

use Drupal\Core\Site\Settings;
use Drupal\flysystem\Definition\SettingsSource;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests the 2.x settings array compatibility in the settings source.
 *
 * The published Drupal Flysystem 2.x module (and its README) defines
 * wrappers as scheme-keyed entries directly under $settings['flysystem'],
 * each carrying a 'driver' plus a 'config' array — with local config keys
 * like 'root', 'public', and a v1-style 'permissions' map, and extra keys
 * such as 'name', 'description', and 'replicate'. Upgrading a site should
 * not require rewriting that array: the settings source detects the old
 * shape and presents it through the modern two-layer interface, mapping
 * scheme to adapter through a deterministic sanitized id and the 2.x
 * 'public' flag to the binding's publicly-accessible answer.
 *
 * Reference: flysystem 2.3.1 README, CONFIGURATION section.
 */
#[Group('flysystem')]
#[CoversNothing]
final class LegacySettingsSourceCompatibilityTest extends TestCase {

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    new Settings([]);
    parent::tearDown();
  }

  /**
   * Verifies a legacy layer presents through the modern adapter layer.
   */
  public function testLegacyDriversBecomeAdapterDefinitions(): void {
    new Settings(['flysystem' => [
      'local-example' => [
        'driver' => 'local',
        'config' => [
          'root' => 'sites/default/files/flysystem',
          'public' => TRUE,
          'permissions' => [
            'file' => ['public' => 0644, 'private' => 0600],
            'dir' => ['public' => 0755, 'private' => 0700],
          ],
          'name' => 'Custom name',
          'description' => 'Custom description',
        ],
      ],
    ]]);
    $source = new SettingsSource();
    $adapters = $source->getAdapterDefinitions();
    self::assertSame(['local_example' => [
      'plugin' => 'local',
      'config' => [
        'root' => 'sites/default/files/flysystem',
        'public' => TRUE,
        'permissions' => [
          'file' => ['public' => 0644, 'private' => 0600],
          'dir' => ['public' => 0755, 'private' => 0700],
        ],
        'name' => 'Custom name',
        'description' => 'Custom description',
      ],
    ]], $adapters, 'The driver becomes the plugin; the config, including unrecognized 2.x keys, passes through unchanged — inert keys must not break existing sites.');
  }

  /**
   * Verifies a legacy layer presents through the modern binding layer.
   */
  public function testLegacySchemesBecomeBindings(): void {
    new Settings(['flysystem' => [
      'local-example' => ['driver' => 'local', 'config' => ['root' => 'files', 'public' => TRUE]],
      'sftpbackup' => ['driver' => 'sftp', 'config' => ['root' => '/srv/backup']],
    ]]);
    $source = new SettingsSource();
    $bindings = $source->getSchemeBindings();
    self::assertSame([
      'local-example' => ['adapter' => 'local_example', 'publicly_accessible' => TRUE],
      'sftpbackup' => ['adapter' => 'sftpbackup'],
    ], $bindings, 'Every scheme becomes a binding to its sanitized adapter id; the 2.x config "public" flag becomes the binding-level publicly-accessible answer, and only when it is set.');
  }

  /**
   * Verifies sanitization is deterministic and collision-safe.
   */
  public function testSanitizedIdsAreDeterministicAndCollisionFree(): void {
    // Sorted scheme order is a+b, a-b, a_b ('+' < '-' < '_'), so the bare
    // sanitized id belongs to a+b and later schemes take the suffixes.
    new Settings(['flysystem' => [
      'a-b' => ['driver' => 'local', 'config' => ['root' => 'from-hyphen']],
      'a_b' => ['driver' => 'local', 'config' => ['root' => 'from-underscore']],
      'a+b' => ['driver' => 'local', 'config' => ['root' => 'from-plus']],
    ]]);
    $source = new SettingsSource();
    self::assertSame([
      'a_b' => ['plugin' => 'local', 'config' => ['root' => 'from-plus']],
      'a_b_2' => ['plugin' => 'local', 'config' => ['root' => 'from-hyphen']],
      'a_b_3' => ['plugin' => 'local', 'config' => ['root' => 'from-underscore']],
    ], $source->getAdapterDefinitions(), 'Colliding sanitized ids get stable suffixes in sorted scheme order, each keeping its own configuration.');
    self::assertSame([
      'a+b' => ['adapter' => 'a_b'],
      'a-b' => ['adapter' => 'a_b_2'],
      'a_b' => ['adapter' => 'a_b_3'],
    ], $source->getSchemeBindings());
  }

  /**
   * Verifies modern layers are untouched by legacy detection.
   */
  public function testModernShapeIsPassedThroughUntouched(): void {
    $layer = [
      'adapters' => ['main' => ['plugin' => 'local', 'config' => ['root' => 'files']]],
      'bindings' => ['public' => ['adapter' => 'main']],
    ];
    new Settings(['flysystem' => $layer]);
    $source = new SettingsSource();
    self::assertSame($layer['adapters'], $source->getAdapterDefinitions());
    self::assertSame($layer['bindings'], $source->getSchemeBindings());
  }

  /**
   * Verifies mixed content: legacy detection keys on driver entries.
   */
  public function testLayerWithoutDriversIsNotLegacy(): void {
    $layer = ['adapters' => [], 'bindings' => ['public' => ['adapter' => 'main']]];
    new Settings(['flysystem' => $layer]);
    $source = new SettingsSource();
    self::assertSame($layer['bindings'], $source->getSchemeBindings(), 'A modern-shaped layer with empty adapters is not reinterpreted.');
  }

  /**
   * Verifies a legacy-only layer yields empty bindings under 'adapters'.
   */
  #[DataProvider('nonLegacyLayerProvider')]
  public function testModernLayersSkipLegacyNormalization(array $layer): void {
    new Settings(['flysystem' => $layer]);
    $source = new SettingsSource();
    self::assertSame($layer['adapters'] ?? [], $source->getAdapterDefinitions());
    self::assertSame($layer['bindings'] ?? [], $source->getSchemeBindings());
  }

  /**
   * @return array<string, array{0: array}>
   *   Modern-shaped and empty layers.
   */
  public static function nonLegacyLayerProvider(): array {
    return [
      'modern with adapters key' => [[
        'adapters' => ['x' => ['plugin' => 'local']],
        'bindings' => ['public' => ['adapter' => 'x']],
      ]],
      'modern with bindings key only' => [[
        'bindings' => [],
      ]],
      'empty' => [[]],
    ];
  }

  /**
   * Verifies normalization keeps the public flag type-honest.
   */
  public function testPublicFlagOnlyMapsWhenBoolean(): void {
    new Settings(['flysystem' => [
      'one' => ['driver' => 'local', 'config' => ['root' => 'r', 'public' => FALSE]],
      'two' => ['driver' => 'local', 'config' => ['root' => 'r', 'public' => 'yes']],
    ]]);
    $bindings = (new SettingsSource())->getSchemeBindings();
    self::assertArrayHasKey('publicly_accessible', $bindings['one']);
    self::assertFalse($bindings['one']['publicly_accessible']);
    self::assertArrayNotHasKey('publicly_accessible', $bindings['two'], 'Only a real boolean drives the binding-level answer; other values stay inert in the adapter config.');
  }

}
