<?php

declare(strict_types=1);

namespace Drupal\flysystem\Tests\Unit\Definition;

use Drupal\flysystem\Definition\DefinitionChecksum;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests the definition checksum used as the operator identity key.
 *
 * The factory memoizes built operators under this key, so two properties
 * are load-bearing: stability (the same effective definition always keys
 * the same, regardless of how the array was assembled) and sensitivity
 * (a real difference in plugin or configuration always keys differently).
 *
 * Secrets deliberately key as references, never as resolved values: a
 * configuration value written as an environment reference hashes the
 * reference token itself. The environment can change between requests,
 * processes, and deploys without silently forking operator identity —
 * and the resolved secret never reaches the hash at all.
 */
#[Group('flysystem')]
#[CoversNothing]
final class DefinitionChecksumTest extends TestCase {

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('FLYSYSTEM_TEST_SECRET');
    parent::tearDown();
  }

  /**
   * Verifies the same definition produces the same key, repeatedly.
   */
  public function testSameDefinitionProducesSameKey(): void {
    $definition = ['plugin' => 'local', 'config' => ['root' => 'sites/default/files']];
    $first = DefinitionChecksum::calculate($definition);
    $second = DefinitionChecksum::calculate($definition);
    self::assertSame($first, $second);
    self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first, 'The identity key must be a stable hex digest.');
  }

  /**
   * Verifies key order never matters, at any depth.
   */
  public function testReorderedConfigProducesSameKey(): void {
    $one = [
      'plugin' => 'local',
      'config' => [
        'root' => 'files',
        'unix_visibility' => [
          'file' => ['public' => 0664, 'private' => 0600],
          'dir' => ['public' => 0775, 'private' => 0700],
        ],
      ],
    ];
    $reordered = [
      'config' => [
        'unix_visibility' => [
          'dir' => ['private' => 0700, 'public' => 0775],
          'file' => ['private' => 0600, 'public' => 0664],
        ],
        'root' => 'files',
      ],
      'plugin' => 'local',
    ];
    self::assertSame(DefinitionChecksum::calculate($one), DefinitionChecksum::calculate($reordered), 'A merged definition assembled from a different source order is the same definition.');
  }

  /**
   * Verifies a changed root (prefix) keys differently.
   */
  public function testChangedRootProducesDifferentKey(): void {
    $before = ['plugin' => 'local', 'config' => ['root' => 'sites/default/files']];
    $after = ['plugin' => 'local', 'config' => ['root' => 'sites/other/files']];
    self::assertNotSame(DefinitionChecksum::calculate($before), DefinitionChecksum::calculate($after));
  }

  /**
   * Verifies the plugin identity participates in the key.
   */
  public function testDifferentPluginProducesDifferentKey(): void {
    $config = ['config' => ['shared' => TRUE]];
    self::assertNotSame(
      DefinitionChecksum::calculate(['plugin' => 'local'] + $config),
      DefinitionChecksum::calculate(['plugin' => 's3'] + $config),
    );
  }

  /**
   * Verifies env references key by name: value churn changes nothing.
   */
  public function testEnvReferenceKeyIgnoresTheResolvedValue(): void {
    $definition = [
      'plugin' => 's3',
      'config' => [
        'bucket' => 'cdn',
        'secret' => 'env:FLYSYSTEM_TEST_SECRET',
      ],
    ];
    putenv('FLYSYSTEM_TEST_SECRET=first-value');
    $before = DefinitionChecksum::calculate($definition);
    putenv('FLYSYSTEM_TEST_SECRET=second-value');
    $after = DefinitionChecksum::calculate($definition);
    self::assertSame($before, $after, 'The same environment reference must key identically whatever it resolves to.');
  }

  /**
   * Verifies renaming an env reference keys differently (it is a new reference).
   */
  public function testEnvReferenceRenamingProducesDifferentKey(): void {
    $base = ['plugin' => 's3', 'config' => ['bucket' => 'cdn']];
    $one = $base + ['secret' => 'env:FLYSYSTEM_TEST_SECRET_A'];
    $two = $base + ['secret' => 'env:FLYSYSTEM_TEST_SECRET_B'];
    self::assertNotSame(DefinitionChecksum::calculate($one), DefinitionChecksum::calculate($two));
  }

  /**
   * Verifies list values are order-significant, unlike mapping keys.
   */
  public function testListOrderIsSignificant(): void {
    $one = ['plugin' => 'local', 'config' => ['allowed' => ['a', 'b']]];
    $two = ['plugin' => 'local', 'config' => ['allowed' => ['b', 'a']]];
    self::assertNotSame(DefinitionChecksum::calculate($one), DefinitionChecksum::calculate($two), 'Lists carry meaning in their order; only named mappings are normalized.');
  }

  /**
   * Verifies type-distinct values key apart ('1' versus 1).
   */
  public function testTypesAreDistinguished(): void {
    $stringy = ['plugin' => 'local', 'config' => ['depth' => '1']];
    $numeric = ['plugin' => 'local', 'config' => ['depth' => 1]];
    self::assertNotSame(DefinitionChecksum::calculate($stringy), DefinitionChecksum::calculate($numeric));
  }

  /**
   * Verifies an empty definition still keys (and keys differently per plugin).
   */
  public function testMinimalDefinitionsKeyStably(): void {
    self::assertNotSame(
      DefinitionChecksum::calculate(['plugin' => 'local', 'config' => []]),
      DefinitionChecksum::calculate(['plugin' => 'memory', 'config' => []]),
    );
    self::assertSame(
      DefinitionChecksum::calculate(['plugin' => 'local']),
      DefinitionChecksum::calculate(['plugin' => 'local', 'config' => []]),
      'A missing config section is the same definition as an empty one.',
    );
  }

}
