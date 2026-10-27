<?php

declare(strict_types=1);

namespace Drupal\flysystem\Tests\Unit\Definition;

use Drupal\flysystem\Definition\DefinitionChecksumTrait;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests the definition checksum used as the operator identity key.
 *
 * The checksum ships as a trait with a protected method, consumed by the
 * factory that memoizes operators (and available to the future
 * verification tool the same way): it is internal machinery, not an API
 * for adapter authors, who implement the plugin interface and never
 * compute identity keys. The shape assertions below pin that decision.
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
   * A minimal host exposing the trait for testing, as the factory will use it.
   *
   * @param array $definition
   *   A merged adapter definition.
   *
   * @return string
   *   The identity digest computed through the trait.
   */
  private function checksum(array $definition): string {
    return (new ChecksumHost())->compute($definition);
  }

  /**
   * Verifies the trait shape: no static methods, checksum entry protected.
   */
  public function testTraitShapeIsProtectedAndNonStatic(): void {
    $reflection = new \ReflectionClass(DefinitionChecksumTrait::class);
    self::assertTrue($reflection->isTrait(), 'The checksum is composed by consumers, not called as a global utility.');
    foreach ($reflection->getMethods() as $method) {
      self::assertFalse($method->isStatic(), sprintf('Trait method %s() must not be static.', $method->getName()));
    }
    self::assertTrue($reflection->getMethod('definitionChecksum')->isProtected(), 'The checksum is protected: internal to composing classes, not third-party API.');
  }

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
    $first = $this->checksum($definition);
    $second = $this->checksum($definition);
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
    self::assertSame(
      $this->checksum($one),
      $this->checksum($reordered),
      'A merged definition assembled from a different source order is the same definition.',
    );
  }

  /**
   * Verifies a changed root (prefix) keys differently.
   */
  public function testChangedRootProducesDifferentKey(): void {
    $before = ['plugin' => 'local', 'config' => ['root' => 'sites/default/files']];
    $after = ['plugin' => 'local', 'config' => ['root' => 'sites/other/files']];
    self::assertNotSame($this->checksum($before), $this->checksum($after));
  }

  /**
   * Verifies the plugin identity participates in the key.
   */
  public function testDifferentPluginProducesDifferentKey(): void {
    $config = ['config' => ['shared' => TRUE]];
    self::assertNotSame(
      $this->checksum(['plugin' => 'local'] + $config),
      $this->checksum(['plugin' => 's3'] + $config),
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
    $before = $this->checksum($definition);
    putenv('FLYSYSTEM_TEST_SECRET=second-value');
    $after = $this->checksum($definition);
    self::assertSame($before, $after, 'The same environment reference must key identically whatever it resolves to.');
  }

  /**
   * Verifies renaming an env reference keys differently.
   *
   * A renamed reference is a different reference.
   */
  public function testEnvReferenceRenamingProducesDifferentKey(): void {
    $base = ['plugin' => 's3', 'config' => ['bucket' => 'cdn']];
    $one = $base + ['secret' => 'env:FLYSYSTEM_TEST_SECRET_A'];
    $two = $base + ['secret' => 'env:FLYSYSTEM_TEST_SECRET_B'];
    self::assertNotSame($this->checksum($one), $this->checksum($two));
  }

  /**
   * Verifies list values are order-significant, unlike mapping keys.
   */
  public function testListOrderIsSignificant(): void {
    $one = ['plugin' => 'local', 'config' => ['allowed' => ['a', 'b']]];
    $two = ['plugin' => 'local', 'config' => ['allowed' => ['b', 'a']]];
    self::assertNotSame(
      $this->checksum($one),
      $this->checksum($two),
      'Lists carry meaning in their order; only named mappings are normalized.',
    );
  }

  /**
   * Verifies type-distinct values key apart ('1' versus 1).
   */
  public function testTypesAreDistinguished(): void {
    $stringy = ['plugin' => 'local', 'config' => ['depth' => '1']];
    $numeric = ['plugin' => 'local', 'config' => ['depth' => 1]];
    self::assertNotSame($this->checksum($stringy), $this->checksum($numeric));
  }

  /**
   * Verifies an empty definition still keys (and keys differently per plugin).
   */
  public function testMinimalDefinitionsKeyStably(): void {
    self::assertNotSame(
      $this->checksum(['plugin' => 'local', 'config' => []]),
      $this->checksum(['plugin' => 'memory', 'config' => []]),
    );
    self::assertSame(
      $this->checksum(['plugin' => 'local']),
      $this->checksum(['plugin' => 'local', 'config' => []]),
      'A missing config section is the same definition as an empty one.',
    );
  }

}

/**
 * Host class composing the checksum trait, mirroring factory consumption.
 */
final class ChecksumHost {

  use DefinitionChecksumTrait;

  /**
   * Public passthrough standing in for the factory's internal use.
   *
   * @param array $definition
   *   A merged adapter definition.
   *
   * @return string
   *   The identity digest.
   */
  public function compute(array $definition): string {
    return $this->definitionChecksum($definition);
  }

}
