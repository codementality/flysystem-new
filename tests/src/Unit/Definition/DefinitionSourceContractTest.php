<?php

declare(strict_types=1);

namespace Drupal\flysystem\Tests\Unit\Definition;

use Drupal\Core\Site\Settings;
use Drupal\flysystem\Definition\AdapterDefinitionSourceInterface;
use Drupal\flysystem\Definition\ArrayDefinitionSource;
use Drupal\flysystem\Definition\SettingsSource;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests for M1-04 (Part 1): the definition source contract.
 *
 * The contract: a source returns plain-array layers (adapter definitions
 * plus scheme bindings) and nothing else — no validation (M1-05), no
 * merging/precedence (M1-06), no normalization. D4/D8 must have no
 * source-specific branches anywhere below the resolver, so the interface
 * carries no origin/type sniffing method either (frozen by reflection).
 *
 * SettingsSource reads $settings['flysystem'] through the static Settings
 * facade, the same pre-container pattern core itself uses for wrapper
 * configuration (PublicStream::basePath() → Settings::get(), evaluation
 * §1.1, web/core/lib/Drupal/Core/StreamWrapper/PublicStream.php:96-109;
 * the singleton assignment in web/core/lib/Drupal/Core/Site/Settings.php:54-57
 * is what makes these unit tests possible without a container).
 *
 * ArrayDefinitionSource is the stand-in for M3's config-entity source: a
 * fake that replaces a definition *source*, deliberately not the memory
 * *adapter* (D10 distinction). Identical resolver behavior against both
 * sources is the seam M3 plugs into.
 */
#[Group('flysystem')]
#[CoversNothing]
final class DefinitionSourceContractTest extends TestCase {

  /**
   * A realistic two-adapter, two-binding settings layer.
   *
   * @return array<string, mixed>
   *   The raw flysystem settings layer.
   */
  public static function sampleLayer(): array {
    return [
      'adapters' => [
        'main' => ['plugin' => 'local', 'config' => ['root' => 'sites/default/files']],
        'secret' => ['plugin' => 'local', 'config' => ['root' => '/../private-files']],
      ],
      'bindings' => [
        'public' => ['adapter' => 'main'],
        'private' => ['adapter' => 'secret', 'publicly_accessible' => FALSE],
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
    // Ratchet: no getSourceType()/origin methods — D4/D8 forbid any
    // source-specific branching below the resolver, and an interface
    // carrying origin would invite it. Later methods are a deliberate
    // decision, reviewed as such.
    self::assertSame(['getAdapterDefinitions', 'getSchemeBindings'], $methods, 'The definition source seam is exactly two plain-array layers in M1.');
  }

  /**
   * Verifies SettingsSource reads the flysystem layer from Settings.
   */
  public function testSettingsSourceReadsTheFlysystemLayer(): void {
    $layer = self::sampleLayer();
    new Settings(['flysystem' => $layer]);

    $source = new SettingsSource();
    self::assertInstanceOf(AdapterDefinitionSourceInterface::class, $source);
    self::assertSame($layer['adapters'], $source->getAdapterDefinitions());
    self::assertSame($layer['bindings'], $source->getSchemeBindings());
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
    $layer = self::sampleLayer();
    new Settings(['file_public_path' => 'unrelated', 'fly_privates' => ['adapters' => 'trap'], 'flysystem' => $layer]);
    $source = new SettingsSource();
    self::assertSame($layer['adapters'], $source->getAdapterDefinitions());
    self::assertSame($layer['bindings'], $source->getSchemeBindings());
  }

  /**
   * Verifies fake and settings sources are indistinguishable through the seam.
   */
  #[DataProvider('layerProvider')]
  public function testSourcesAreIdenticalThroughTheInterface(array $layer): void {
    new Settings(['flysystem' => $layer]);
    /** @var \Drupal\flysystem\Definition\AdapterDefinitionSourceInterface[] $sources */
    $sources = [
      new SettingsSource(),
      new ArrayDefinitionSource($layer),
    ];
    foreach ($sources as $source) {
      self::assertInstanceOf(AdapterDefinitionSourceInterface::class, $source);
    }
    // The resolver (M1-06) will consume these outputs; identity here is the
    // seam proof — swapping a source requires no resolver change.
    self::assertEquals($sources[0]->getAdapterDefinitions(), $sources[1]->getAdapterDefinitions());
    self::assertEquals($sources[0]->getSchemeBindings(), $sources[1]->getSchemeBindings());
  }

  /**
   * Data provider: the sample layer, an empty layer, a partial layer.
   *
   * @return array<string, array{0: array<string, mixed>}>
   *   Named layers to feed both source implementations.
   */
  public static function layerProvider(): array {
    return [
      'full sample' => [self::sampleLayer()],
      'empty' => [[]],
      'adapters only' => [['adapters' => ['main' => ['plugin' => 'local']]]],
      'bindings only' => [['bindings' => ['s3_assets' => ['adapter' => 'cdn']]]],
    ];
  }

  /**
   * Verifies sources return raw layers untouched — no normalize/validate/merge.
   */
  public function testSourcesReturnRawLayersUntouched(): void {
    // Malformed entries (bad id types, unknown keys) must pass through a
    // source verbatim: validation is M1-05's typed job, precedence M1-06's.
    $malformed = [
      'adapters' => ['main' => ['plugin' => 'local', 'unexpected_key' => 'kept']],
      'bindings' => ['public' => ['adapter' => 'main', 'disabled' => 'not-a-bool']],
    ];
    new Settings(['flysystem' => $malformed]);
    $source = new SettingsSource();
    self::assertSame($malformed['adapters'], $source->getAdapterDefinitions());
    self::assertSame($malformed['bindings'], $source->getSchemeBindings());

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
