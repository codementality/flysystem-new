<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Unit\Definition;

use Drupal\Core\Site\Settings;
use Drupal\flysystem\Definition\AdapterDefinitionSourceInterface;
use Drupal\flysystem\Definition\ArrayDefinitionSource;
use Drupal\flysystem\Definition\SettingsSource;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests the definition source contract: plain-array layers, nothing more.
 *
 * The contract: a source returns the two plain-array layers (adapter
 * definitions plus scheme bindings) and nothing else — no validation, no
 * merging/precedence. Presenting the source's own configuration in that
 * shape is the source's only mapping job: the settings source accepts
 * exactly one user-facing format (one entry per scheme, carrying a
 * 'driver') and maps it to the two layers; the array-backed source takes
 * the layers directly. No source-specific branch may exist anywhere below
 * the resolver, so the interface carries no origin/type sniffing method
 * either (frozen by reflection).
 *
 * SettingsSource reads $settings['flysystem'] through the static Settings
 * facade, the same pre-container pattern core itself uses for wrapper
 * configuration (PublicStream::basePath() → Settings::get(),
 * web/core/lib/Drupal/Core/StreamWrapper/PublicStream.php:96-109;
 * the singleton assignment in web/core/lib/Drupal/Core/Site/Settings.php:54-57
 * is what makes these unit tests possible without a container).
 *
 * ArrayDefinitionSource stands in for a future config-entity source
 * (deliberately not the memory *adapter*, which replaces a storage
 * backend). The same effective configuration reaching the resolver
 * through either source is the seam any future source plugs into.
 */
#[Group('flysystem')]
#[CoversNothing]
final class DefinitionSourceContractTest extends TestCase {

  /**
   * The two-section internal layer shape, as a source presents it.
   *
   * @return array<string, mixed>
   *   Adapter definitions and scheme bindings.
   */
  public static function sampleLayer(): array {
    return [
      'adapters' => [
        'public' => ['plugin' => 'local', 'config' => ['root' => 'sites/default/files']],
        'private' => ['plugin' => 'local', 'config' => ['root' => '../private-files']],
      ],
      'bindings' => [
        'public' => ['adapter' => 'public'],
        'private' => ['adapter' => 'private', 'publicly_accessible' => FALSE],
      ],
    ];
  }

  /**
   * The equivalent user-facing settings.php configuration (scheme-keyed).
   *
   * @return array<string, mixed>
   *   The flat flysystem settings layer.
   */
  public static function flatSample(): array {
    return [
      'public' => [
        'driver' => 'local',
        'config' => ['root' => 'sites/default/files'],
      ],
      'private' => [
        'driver' => 'local',
        'config' => ['root' => '../private-files'],
        'publicly_accessible' => FALSE,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // Restore the static Settings singleton to an empty state so no other
    // test observes flysystem values.
    new Settings([]);
    parent::tearDown();
  }

  /**
   * Verifies the interface declares exactly the two array layers.
   */
  public function testInterfaceDeclaresExactlyTwoArrayLayers(): void {
    $reflection = new \ReflectionClass(AdapterDefinitionSourceInterface::class);
    self::assertTrue($reflection->isInterface());

    $methods = [];
    foreach ($reflection->getMethods() as $method) {
      self::assertSame(0, $method->getNumberOfParameters(), sprintf('Source method %s() must take no parameters.', $method->getName()));
      $returnType = $method->getReturnType();
      self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
      self::assertSame('array', $returnType->getName(), sprintf('Source method %s() must return a plain array, not a typed structure — sources stay dumb.', $method->getName()));
      $methods[] = $method->getName();
    }
    sort($methods);
    // Ratchet: no getSourceType()/origin methods — forbidding any
    // source-specific branching below the resolver, and an interface
    // carrying origin would invite it. Later methods are a deliberate
    // decision, reviewed as such.
    self::assertSame(['getAdapterDefinitions', 'getSchemeBindings'], $methods, 'The definition source seam is exactly two plain-array layers.');
  }

  /**
   * Verifies SettingsSource reads the flysystem layer from Settings.
   */
  public function testSettingsSourceReadsTheFlysystemLayer(): void {
    new Settings(['flysystem' => self::flatSample()]);

    $source = new SettingsSource();
    $expected = self::sampleLayer();
    self::assertInstanceOf(AdapterDefinitionSourceInterface::class, $source);
    // Order-insensitive on purpose: normalization sorts scheme order, which
    // is a detail; the pairs are the contract.
    self::assertEquals($expected['adapters'], $source->getAdapterDefinitions(), 'Scheme-keyed settings present as adapter definitions under sanitized ids.');
    self::assertEquals($expected['bindings'], $source->getSchemeBindings(), 'Binding siblings ride into the binding layer untouched.');
  }

  /**
   * Verifies an absent flysystem layer reads as empty, not as an error.
   */
  public function testMissingFlysystemLayerIsSilentlyEmpty(): void {
    new Settings([]);
    $source = new SettingsSource();
    self::assertSame([], $source->getAdapterDefinitions());
    self::assertSame([], $source->getSchemeBindings());
  }

  /**
   * Verifies only the known keys are consulted: flysystem is the one layer.
   */
  public function testSettingsSourceIgnoresUnrelatedSettings(): void {
    new Settings([
      'file_public_path' => 'unrelated',
      'fly_privates' => ['adapters' => 'trap'],
      'flysystem' => self::flatSample() + ['note' => 'a stray string is not a scheme entry'],
    ]);
    $source = new SettingsSource();
    $expected = self::sampleLayer();
    self::assertEquals($expected['adapters'], $source->getAdapterDefinitions());
    self::assertEquals($expected['bindings'], $source->getSchemeBindings());
  }

  /**
   * Verifies fake and settings sources are indistinguishable through the seam.
   */
  #[DataProvider('equivalentConfigsProvider')]
  public function testSourcesAreIdenticalThroughTheInterface(array $settingsLayer, array $arrayLayer): void {
    new Settings(['flysystem' => $settingsLayer]);
    /** @var \Drupal\flysystem\Definition\AdapterDefinitionSourceInterface[] $sources */
    $sources = [
      new SettingsSource(),
      new ArrayDefinitionSource($arrayLayer),
    ];
    foreach ($sources as $source) {
      self::assertInstanceOf(AdapterDefinitionSourceInterface::class, $source);
    }
    // The binding resolver consumes these outputs; identity here is the
    // seam proof — swapping a source requires no resolver change.
    self::assertEquals($sources[0]->getAdapterDefinitions(), $sources[1]->getAdapterDefinitions());
    self::assertEquals($sources[0]->getSchemeBindings(), $sources[1]->getSchemeBindings());
  }

  /**
   * Verifies a non-array flysystem layer reads as empty, never as a crash.
   */
  public function testNonArrayFlysystemLayerReadsAsEmpty(): void {
    new Settings(['flysystem' => 'oops']);
    $source = new SettingsSource();
    self::assertSame([], $source->getAdapterDefinitions());
    self::assertSame([], $source->getSchemeBindings());
  }

  /**
   * Data provider: user settings equivalent to a given array layer.
   *
   * @return array<string, array{0: array, 1: array}>
   *   Named pairs of settings-shaped and layer-shaped configuration.
   */
  public static function equivalentConfigsProvider(): array {
    $sample = self::sampleLayer();
    return [
      'full sample' => [self::flatSample(), $sample],
      'empty' => [[], []],
      'single scheme' => [
        ['cdn' => ['driver' => 'local', 'config' => ['root' => 'cdn']]],
        [
          'adapters' => ['cdn' => ['plugin' => 'local', 'config' => ['root' => 'cdn']]],
          'bindings' => ['cdn' => ['adapter' => 'cdn']],
        ],
      ],
    ];
  }

  /**
   * Verifies sources do not validate: questionable values pass through.
   */
  public function testSourcesPassQuestionableContentUntouched(): void {
    // Structural judgment belongs to consumers. The settings source maps
    // shape but not content: unrecognized config keys ride through and
    // non-boolean binding siblings are neither corrected nor rejected.
    new Settings([
      'flysystem' => [
        'public' => [
          'driver' => 'local',
          'config' => ['root' => 'files', 'name' => 'kept', 'replicate' => 'ignored-later'],
          'disabled' => 'not-a-bool',
        ],
      ],
    ]);
    $source = new SettingsSource();
    self::assertSame([
      'public' => [
        'plugin' => 'local',
        'config' => [
          'root' => 'files',
          'name' => 'kept',
          'replicate' => 'ignored-later',
        ],
      ],
    ], $source->getAdapterDefinitions());
    self::assertSame([
      'public' => ['adapter' => 'public'],
    ], $source->getSchemeBindings(), 'A non-boolean disabled sibling is not copied into the binding; validation has its say later.');

    // The array source passes sections verbatim, validation-free.
    $malformed = [
      'adapters' => ['main' => ['plugin' => 'local', 'unexpected_key' => 'kept']],
      'bindings' => ['public' => ['adapter' => 'main', 'disabled' => 'not-a-bool']],
    ];
    $fake = new ArrayDefinitionSource($malformed);
    self::assertSame($malformed['adapters'], $fake->getAdapterDefinitions());
    self::assertSame($malformed['bindings'], $fake->getSchemeBindings());
  }

  /**
   * Verifies the ArrayDefinitionSource consumes the same layer shape.
   */
  public function testArraySourceTakesTheSettingsLayerVerbatim(): void {
    $layer = self::sampleLayer();
    $source = new ArrayDefinitionSource($layer);
    self::assertInstanceOf(AdapterDefinitionSourceInterface::class, $source);
    self::assertSame($layer['adapters'], $source->getAdapterDefinitions());
    self::assertSame($layer['bindings'], $source->getSchemeBindings());
  }

}
