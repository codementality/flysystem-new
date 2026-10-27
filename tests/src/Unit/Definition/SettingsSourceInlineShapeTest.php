<?php

declare(strict_types=1);

namespace Drupal\flysystem\Tests\Unit\Definition;

use Drupal\Core\Site\Settings;
use Drupal\flysystem\Definition\SettingsSource;
use Drupal\flysystem\Exception\InvalidSettingsStructureException;
use PHPUnit\Framework\Attributes\CoversNothing;
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
  public function testFlatDriversBecomeAdapterDefinitions(): void {
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
  public function testFlatSchemesBecomeBindings(): void {
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
   * Verifies sanitized ids stay deterministic where collisions can occur.
   *
   * The scheme grammar excludes underscores, so a hyphenated name can
   * never collide with a legitimate underscore name; case is the only
   * collision path between valid schemes ('Public' versus 'public').
   * Sorted order keeps the assignment deterministic.
   */
  public function testCaseCollisionsGetDeterministicSuffixes(): void {
    new Settings([
      'flysystem' => [
        'Public' => ['driver' => 'local', 'config' => ['root' => 'upper']],
        'public' => ['driver' => 'local', 'config' => ['root' => 'lower']],
      ],
    ]);
    $source = new SettingsSource();
    $adapters = $source->getAdapterDefinitions();
    self::assertSame(
      ['public', 'public_2'],
      array_keys($adapters),
      'Both colliding schemes keep a distinct, grammar-legal adapter id in sorted order.',
    );
    self::assertSame('upper', $adapters['public']['config']['root']);
    self::assertSame('lower', $adapters['public_2']['config']['root']);
  }

  /**
   * Verifies modern layers are untouched by legacy detection.
   */
  public function testTwoSectionWriteFormIsRejectedWithGuidance(): void {
    $layer = [
      'adapters' => ['main' => ['plugin' => 'local', 'config' => ['root' => 'files']]],
      'bindings' => ['public' => ['adapter' => 'main']],
    ];
    new Settings(['flysystem' => $layer]);
    $source = new SettingsSource();
    try {
      $source->getAdapterDefinitions();
      self::fail('Expected a typed structure exception for the two-section write form.');
    }
    catch (InvalidSettingsStructureException $e) {
      self::assertStringContainsString('adapters', $e->getMessage());
      self::assertStringContainsString('driver', $e->getMessage(), 'The error must teach the supported format, not just reject the old one.');
    }
  }

  /**
   * Verifies an empty layer yields empty layers rather than an error.
   */
  public function testEmptyLayerYieldsEmptyLayers(): void {
    new Settings(['flysystem' => []]);
    $source = new SettingsSource();
    self::assertSame([], $source->getAdapterDefinitions());
    self::assertSame([], $source->getSchemeBindings());
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
