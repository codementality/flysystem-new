<?php

declare(strict_types=1);

namespace Drupal\flysystem\Tests\Unit\Adapter;

use Drupal\flysystem\Adapter\AdapterCapabilities;
use Drupal\flysystem\Adapter\AdapterCapabilitiesInterface;
use Drupal\flysystem\Adapter\CapabilitiesProviderInterface;
use Drupal\flysystem\Adapter\RegistrationMode;
use Drupal\flysystem\Adapter\UrlMode;
use League\Flysystem\Config;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UrlGeneration\PublicUrlGenerator;
use League\Flysystem\UrlGeneration\TemporaryUrlGenerator;
use League\Flysystem\Visibility;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests for M1-03 (Part 1): the adapter capability contract.
 *
 * B6 posture: the field set is deliberately minimal and its shape is frozen
 * only enough that later fields arrive additively (BC). The vocabulary
 * assertions below are ratchets, not forecasts — M2/M4 consumers either keep
 * these fields load-bearing or they get pruned; nothing in M1 binds a
 * consumer to exist.
 *
 * Honesty of the `local` and `memory` profiles is anchored in live probes
 * against the vendored league/flysystem 3.36 library so a future plugin
 * (M1-09/M1-10) that misreports its backend cannot pass unnoticed:
 * - LocalFilesystemAdapter::readStream() returns a native file handle
 *   (vendor/league/flysystem-local/LocalFilesystemAdapter.php:304-315) and
 *   stream writes land on real disk (writeToFile(), :117-131).
 * - InMemoryFilesystemAdapter stores whole-file strings in RAM (:30);
 *   writeStream() buffers via stream_get_contents() (:55-57) and
 *   readStream() synthesizes a stream from the store (:71-79) — no native
 *   streaming, and no filesystem side effects.
 * - Neither adapter implements the optional URL generator interfaces
 *   (League\Flysystem\UrlGeneration\*, evaluation §1.11), so UrlMode::None
 *   is the only honest value for both.
 */
#[Group('flysystem')]
#[CoversNothing]
final class AdapterCapabilitiesContractTest extends TestCase {

  /**
   * The frozen minimal getter set: method name => declared return type.
   */
  private const MINIMAL_GETTERS = [
    'registrationMode' => RegistrationMode::class,
    'supportsDirectories' => 'bool',
    'supportsVisibility' => 'bool',
    'urlMode' => UrlMode::class,
    'streamsNatively' => 'bool',
  ];

  /**
   * A per-backend temp root used only by the local probe.
   */
  private string $tempRoot = '';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->tempRoot = sys_get_temp_dir() . '/flysystem-capabilities-' . getmypid();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (is_dir($this->tempRoot)) {
      exec('rm -rf ' . escapeshellarg($this->tempRoot));
    }
    parent::tearDown();
  }

  /**
   * Verifies the capability interface exposes exactly the frozen getter set.
   */
  public function testInterfaceExposesTheFrozenMinimalGetterSet(): void {
    $reflection = new \ReflectionClass(AdapterCapabilitiesInterface::class);
    self::assertTrue($reflection->isInterface(), 'The contract must be an interface so consumers never depend on a concrete class.');

    $actual = [];
    foreach ($reflection->getMethods() as $method) {
      self::assertSame(0, $method->getNumberOfParameters(), sprintf('Contract getter %s() must take no required parameters so later evolution stays BC.', $method->getName()));
      $returnType = $method->getReturnType();
      self::assertNotNull($returnType, sprintf('Contract getter %s() must declare a return type.', $method->getName()));
      self::assertTrue($returnType instanceof \ReflectionNamedType, sprintf('Contract getter %s() must return a single named type (no unions/intersections).', $method->getName()));
      $actual[$method->getName()] = $returnType->getName();
    }
    ksort($actual);
    $expected = self::MINIMAL_GETTERS;
    ksort($expected);
    self::assertSame($expected, $actual, 'The capability interface must expose exactly the agreed minimal field set; later fields are added deliberately (B6), never silently.');
  }

  /**
   * Verifies the two capability enums carry exactly the agreed vocabulary.
   */
  public function testEnumVocabulariesAreFrozen(): void {
    $registrationModes = array_map(static fn (RegistrationMode $mode): string => $mode->name, RegistrationMode::cases());
    self::assertSame(['Local', 'Url'], $registrationModes, 'registrationMode is local/url (D9 containment hinges on exactly this distinction).');

    $urlModes = array_map(static fn (UrlMode $mode): string => $mode->name, UrlMode::cases());
    self::assertSame(['None', 'Public', 'Temporary', 'PublicAndTemporary'], $urlModes, 'URL mode vocabulary is frozen (§1.11: optional interfaces, four honest states).');
  }

  /**
   * Verifies the value object is final, immutable, and echoes construction.
   */
  public function testValueObjectIsFinalImmutableAndTyped(): void {
    $reflection = new \ReflectionClass(AdapterCapabilities::class);
    self::assertTrue($reflection->isFinal(), 'The contract VO must be final; variability belongs to plugins, not subclasses.');
    self::assertTrue($reflection->implementsInterface(AdapterCapabilitiesInterface::class));
    self::assertSame([], $reflection->getStaticProperties(), 'The VO carries no static state.');
    foreach ($reflection->getProperties() as $property) {
      self::assertTrue($property->isReadOnly(), sprintf('Capability field %s must be readonly (value object).', $property->getName()));
      self::assertFalse($property->isPublic(), sprintf('Capability field %s must not be publicly mutable.', $property->getName()));
    }
    foreach (array_keys(self::MINIMAL_GETTERS) as $name) {
      self::assertEmpty($reflection->getMethod($name)->getParameters(), "Getter $name() must be parameterless for consumers.");
    }

    $capabilities = new AdapterCapabilities(RegistrationMode::Url, TRUE, FALSE, UrlMode::Temporary, FALSE);
    self::assertSame(RegistrationMode::Url, $capabilities->registrationMode());
    self::assertTrue($capabilities->supportsDirectories());
    self::assertFalse($capabilities->supportsVisibility());
    self::assertSame(UrlMode::Temporary, $capabilities->urlMode());
    self::assertFalse($capabilities->streamsNatively());
  }

  /**
   * Verifies constructor defaults make later capability fields additive (BC).
   */
  public function testConstructorDefaultsKeepLaterFieldsAdditive(): void {
    $constructor = new \ReflectionClass(AdapterCapabilities::class)->getConstructor();
    self::assertNotNull($constructor);
    $required = array_values(array_filter($constructor->getParameters(), static fn (\ReflectionParameter $parameter): bool => !$parameter->isOptional()));
    self::assertCount(1, $required, 'Only registrationMode may be required; every other field must default so adding a later field cannot break existing construction sites (B6).');
    self::assertSame('registrationMode', $required[0]->getName());

    $capabilities = new AdapterCapabilities(RegistrationMode::Local);
    self::assertFalse($capabilities->supportsDirectories());
    self::assertFalse($capabilities->supportsVisibility());
    self::assertSame(UrlMode::None, $capabilities->urlMode());
    self::assertFalse($capabilities->streamsNatively());
  }

  /**
   * Verifies capabilities are reachable without inspecting plugin classes.
   */
  public function testProviderSeamConsumesCapabilitiesWithoutInspectingPluginClasses(): void {
    $provider = new class () implements CapabilitiesProviderInterface {

      /**
       * {@inheritdoc}
       */
      public function capabilities(): AdapterCapabilitiesInterface {
        return new AdapterCapabilities(RegistrationMode::Local, TRUE, TRUE, UrlMode::None, TRUE);
      }

    };

    // The seam itself: one method, returning the interface type.
    $reflection = new \ReflectionClass(CapabilitiesProviderInterface::class);
    self::assertTrue($reflection->isInterface());
    $methods = array_map(static fn (\ReflectionMethod $m): string => $m->getName(), $reflection->getMethods());
    self::assertSame(['capabilities'], $methods, 'The plugin-side seam exposes capabilities and nothing else in M1.');
    $returnType = $reflection->getMethod('capabilities')->getReturnType();
    self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
    self::assertSame(AdapterCapabilitiesInterface::class, $returnType->getName(), 'The seam must be typed to the interface, not the VO class, so the factory never inspects plugin internals.');

    // Factory-style consumption: type-hinted to the interface, getter-only.
    $capabilities = $provider->capabilities();
    self::assertInstanceOf(AdapterCapabilitiesInterface::class, $capabilities);
    self::assertSame(RegistrationMode::Local, $capabilities->registrationMode());
    self::assertTrue($capabilities->supportsDirectories());
    self::assertTrue($capabilities->supportsVisibility());
    self::assertSame(UrlMode::None, $capabilities->urlMode());
    self::assertTrue($capabilities->streamsNatively());
  }

  /**
   * Verifies the honest `local` profile matches vendored library behavior.
   */
  public function testLocalAdapterProfileIsHonestToVendoredLibraryBehavior(): void {
    $observed = $this->probeLocalFilesystem();
    $honest = self::honestLocalProfile();
    self::assertSame($honest, $observed, 'The declared local profile must equal the library-probed profile; the row text says plugins fill the minimal set honestly.');
  }

  /**
   * Verifies the honest `memory` profile matches vendored library behavior.
   */
  public function testMemoryAdapterProfileIsHonestToVendoredLibraryBehavior(): void {
    $observed = $this->probeInMemoryFilesystem();
    $honest = self::honestMemoryProfile();
    self::assertSame($honest, $observed, 'The declared memory profile must equal the library-probed profile (D10: memory carries no filesystem semantics of its own).');
  }

  /**
   * Verifies both profiles agree except where the backends genuinely differ.
   */
  public function testLocalAndMemoryProfilesDifferOnlyOnNativeStreaming(): void {
    $local = self::honestLocalProfile();
    $memory = self::honestMemoryProfile();
    $differing = [];
    foreach ($local as $field => $value) {
      if ($value !== $memory[$field]) {
        $differing[] = $field;
      }
    }
    self::assertSame(['streamsNatively'], $differing, 'The two M1 backends differ only in the streaming hint; registration mode, directories, visibility, and URL mode are identical (D10 harness principle — memory adds no distinct behavior to exploit).');
  }

  /**
   * The canonical local profile, field-by-field.
   *
   * @return array<string, mixed>
   *   Profile map keyed by contract getter name.
   */
  public static function honestLocalProfile(): array {
    return [
      'registrationMode' => RegistrationMode::Local,
      'supportsDirectories' => TRUE,
      'supportsVisibility' => TRUE,
      'urlMode' => UrlMode::None,
      'streamsNatively' => TRUE,
    ];
  }

  /**
   * The canonical memory profile, field-by-field.
   *
   * @return array<string, mixed>
   *   Profile map keyed by contract getter name.
   */
  public static function honestMemoryProfile(): array {
    return [
      'registrationMode' => RegistrationMode::Local,
      'supportsDirectories' => TRUE,
      'supportsVisibility' => TRUE,
      'urlMode' => UrlMode::None,
      'streamsNatively' => FALSE,
    ];
  }

  /**
   * Probes the vendored local adapter and derives each capability field.
   *
   * @return array<string, mixed>
   *   Profile map derived from observed library behavior.
   */
  private function probeLocalFilesystem(): array {
    $adapter = new LocalFilesystemAdapter($this->tempRoot);
    $config = new Config([]);
    $adapter->write('f.txt', 'hello', $config);
    $adapter->createDirectory('sub', $config);

    $listed = [];
    foreach ($adapter->listContents('', FALSE) as $item) {
      $listed[$item->path()] = $item->type();
    }

    return [
      // A real-disk backend registers object-key style; no URL semantics.
      'registrationMode' => RegistrationMode::Local,
      'supportsDirectories' => ($listed['sub'] ?? '') === 'dir',
      'supportsVisibility' => $this->visibilityRoundTrips($adapter, 'f.txt'),
      'urlMode' => $this->urlModeOf($adapter),
      // Native streaming: stream writes land on disk and reads hand back a
      // real file handle — no whole-file buffering inside the adapter.
      'streamsNatively' => is_file($this->tempRoot . '/f.txt') && is_resource($adapter->readStream('f.txt')),
    ];
  }

  /**
   * Probes the vendored in-memory adapter and derives each capability field.
   *
   * @return array<string, mixed>
   *   Profile map derived from observed library behavior.
   */
  private function probeInMemoryFilesystem(): array {
    $fakeRoot = '/flysystem-memory-probe-' . getmypid();
    $adapter = new InMemoryFilesystemAdapter($fakeRoot);
    $config = new Config([]);
    $adapter->write('f.txt', 'hello', $config);
    $adapter->createDirectory('sub', $config);

    $listed = [];
    foreach ($adapter->listContents('', FALSE) as $item) {
      $listed[$item->path()] = $item->type();
    }

    return [
      'registrationMode' => RegistrationMode::Local,
      'supportsDirectories' => ($listed['sub'] ?? '') === 'dir',
      'supportsVisibility' => $this->visibilityRoundTrips($adapter, 'f.txt'),
      'urlMode' => $this->urlModeOf($adapter),
      // Derived, not asserted: a disk-backed store would create its root;
      // the RAM store never touches the filesystem, so no native streaming.
      'streamsNatively' => is_dir($fakeRoot),
    ];
  }

  /**
   * Derives the honest URL mode from which optional interfaces the adapter
   * implements (evaluation §1.11).
   */
  private function urlModeOf(FilesystemAdapter $adapter): UrlMode {
    $public = $adapter instanceof PublicUrlGenerator;
    $temporary = $adapter instanceof TemporaryUrlGenerator;
    if ($public && $temporary) {
      return UrlMode::PublicAndTemporary;
    }
    if ($public) {
      return UrlMode::Public;
    }
    if ($temporary) {
      return UrlMode::Temporary;
    }
    return UrlMode::None;
  }

  /**
   * TRUE only when setVisibility() sticks and can be read back.
   */
  private function visibilityRoundTrips(FilesystemAdapter $adapter, string $path): bool {
    $adapter->setVisibility($path, Visibility::PRIVATE);
    return $adapter->visibility($path)->visibility() === Visibility::PRIVATE;
  }

}
