<?php

declare(strict_types=1);

namespace Drupal\flysystem\Tests\Unit\Definition;

use Drupal\Core\Site\Settings;
use Drupal\flysystem\Definition\SettingsSource;
use Drupal\flysystem\Validation\SettingsValidator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests the flat (scheme-keyed) settings shape of the settings source.
 *
 * The recommended user-facing configuration is one entry per scheme,
 * each carrying a 'driver' plus a 'config' array — optionally with the
 * binding options 'disabled' and 'publicly_accessible' as siblings.
 * This is deliberately the shape the published flysystem 2.x module
 * used (its README, CONFIGURATION section), so upgrading sites need no
 * edits and basic configuration stays small; the two-layer
 * adapters/bindings shape remains supported for sharing adapter
 * definitions across schemes. The flat form is presented through the
 * same two internal layers every definition source uses, mapping scheme
 * to adapter through a deterministic sanitized id.
 */
#[Group('flysystem')]
#[CoversNothing]
final class SettingsSourceInlineShapeTest extends TestCase {

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
    new Settings([
      'flysystem' => [
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
      ],
    ]);
    $source = new SettingsSource();
    $adapters = $source->getAdapterDefinitions();
    self::assertSame([
      'local_example' => [
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
      ],
    ], $adapters, 'The driver becomes the plugin; the config, including unrecognized 2.x keys, passes through unchanged — inert keys must not break existing sites.');
  }

  /**
   * Verifies a legacy layer presents through the modern binding layer.
   */
  public function testLegacySchemesBecomeBindings(): void {
    new Settings([
      'flysystem' => [
        'local-example' => ['driver' => 'local', 'config' => ['root' => 'files', 'public' => TRUE]],
        'sftpbackup' => ['driver' => 'sftp', 'config' => ['root' => '/srv/backup']],
      ],
    ]);
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
    // Three 2.x-legal scheme names whose punctuation characters all
    // sanitize to the same underscore — collisions are expected and must
    // stay deterministic. Sorted scheme order is a+b, a-b, a.b ('+' 43 <
    // '-' 45 < '.' 46), so a+b takes the bare sanitized id. These names
    // sanitize to ADAPTER IDS (underscores legal there, per the
    // adapter-id grammar the settings validator enforces); as SCHEMES,
    // a.b would fail that same validator's scheme grammar when touched —
    // this test covers normalization only, and the validator remains the
    // sole authority on legal scheme names.
    new Settings([
      'flysystem' => [
        'a-b' => ['driver' => 'local', 'config' => ['root' => 'from-hyphen']],
        'a.b' => ['driver' => 'local', 'config' => ['root' => 'from-dot']],
        'a+b' => ['driver' => 'local', 'config' => ['root' => 'from-plus']],
      ],
    ]);
    $source = new SettingsSource();
    $adapters = $source->getAdapterDefinitions();
    self::assertSame([
      'a_b' => ['plugin' => 'local', 'config' => ['root' => 'from-plus']],
      'a_b_2' => ['plugin' => 'local', 'config' => ['root' => 'from-hyphen']],
      'a_b_3' => ['plugin' => 'local', 'config' => ['root' => 'from-dot']],
    ], $adapters, 'Colliding sanitized ids get stable suffixes in sorted scheme order, each keeping its own configuration.');
    self::assertSame([
      'a+b' => ['adapter' => 'a_b'],
      'a-b' => ['adapter' => 'a_b_2'],
      'a.b' => ['adapter' => 'a_b_3'],
    ], $source->getSchemeBindings(), 'Binding keys keep the scheme name exactly as configured — the normalizer never rewrites a scheme.');
    foreach (array_keys($adapters) as $id) {
      self::assertMatchesRegularExpression(SettingsValidator::ADAPTER_ID_PATTERN, $id, 'Every generated adapter id must satisfy the adapter-id grammar, underscores included.');
    }
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
   * Provides modern-shaped and empty layers.
   *
   * @return array<string, array{0: array}>
   *   Modern-shaped and empty layers.
   */
  public static function nonLegacyLayerProvider(): array {
    return [
      'modern with adapters key' => [[
        'adapters' => ['x' => ['plugin' => 'local']],
        'bindings' => ['public' => ['adapter' => 'x']],
      ],
      ],
      'modern with bindings key only' => [[
        'bindings' => [],
      ],
      ],
      'empty' => [[]],
    ];
  }

  /**
   * Verifies the flat form can pin a scheme core-native with 'disabled'.
   */
  public function testFlatEntrySupportsDisabledSibling(): void {
    new Settings([
      'flysystem' => [
        'temporary' => [
          'driver' => 'local',
          'disabled' => TRUE,
          'config' => ['root' => 'tmp'],
        ],
      ],
    ]);
    $bindings = (new SettingsSource())->getSchemeBindings();
    self::assertSame([
      'adapter' => 'temporary',
      'disabled' => TRUE,
    ], $bindings['temporary'], 'A disabled sibling must survive into the binding so resolution can pin the scheme core-native.');
  }

  /**
   * Verifies an explicit sibling publicly-accessible flag wins over config.
   */
  public function testFlatEntrySupportsPubliclyAccessibleSibling(): void {
    new Settings([
      'flysystem' => [
        'cdn' => [
          'driver' => 'local',
          'publicly_accessible' => FALSE,
          'config' => ['root' => 'files', 'public' => TRUE],
        ],
      ],
    ]);
    $bindings = (new SettingsSource())->getSchemeBindings();
    self::assertFalse($bindings['cdn']['publicly_accessible'], 'An explicit binding-level answer overrides the config-level public flag.');
  }

  /**
   * Verifies normalization keeps the public flag type-honest.
   */
  public function testPublicFlagOnlyMapsWhenBoolean(): void {
    new Settings([
      'flysystem' => [
        'one' => ['driver' => 'local', 'config' => ['root' => 'r', 'public' => FALSE]],
        'two' => ['driver' => 'local', 'config' => ['root' => 'r', 'public' => 'yes']],
      ],
    ]);
    $bindings = (new SettingsSource())->getSchemeBindings();
    self::assertArrayHasKey('publicly_accessible', $bindings['one']);
    self::assertFalse($bindings['one']['publicly_accessible']);
    self::assertArrayNotHasKey('publicly_accessible', $bindings['two'], 'Only a real boolean drives the binding-level answer; other values stay inert in the adapter config.');
  }

}
