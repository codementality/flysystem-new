<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Unit\StreamWrapper;

use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\PrivateStream;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\Core\StreamWrapper\TemporaryStream;
use Drupal\flysystem\FlysystemServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Tests the container-build-time wrapper registration decisions.
 *
 * The service provider reads the flysystem settings layer and re-points
 * the tagged stream wrapper definitions of bound core schemes — public,
 * private, temporary — at this module's wrapper classes. Everything it
 * decides comes from the settings shape alone; it never resolves a
 * binding, never validates a plugin, and never touches the filesystem.
 * A binding it cannot read at all (wrong top-level shape) is left alone,
 * silently: the site stays core-native and the error surfaces only when
 * something actually uses the scheme.
 */
#[Group('flysystem')]
#[CoversClass(FlysystemServiceProvider::class)]
final class WrapperStubRegistrationTest extends TestCase {

  /**
   * Restores the static settings facade after each test.
   */
  protected function tearDown(): void {
    new Settings([]);
    parent::tearDown();
  }

  /**
   * Builds a container holding core's three storage wrapper definitions.
   */
  protected function containerWithCoreWrappers(): ContainerBuilder {
    $container = new ContainerBuilder();
    $map = [
      'public' => PublicStream::class,
      'temporary' => TemporaryStream::class,
    ];
    foreach ($map as $scheme => $class) {
      $container->setDefinition("stream_wrapper.$scheme", (new Definition($class))
        ->addTag('stream_wrapper', ['scheme' => $scheme]));
    }
    return $container;
  }

  /**
   * No settings layer means no changes: every definition stays core-native.
   */
  public function testEmptySettingsRegisterNothing(): void {
    new Settings(['hash_salt' => 'test']);
    $container = $this->containerWithCoreWrappers();
    (new FlysystemServiceProvider())->register($container);

    self::assertSame(PublicStream::class, $container->getDefinition('stream_wrapper.public')->getClass());
    self::assertSame(TemporaryStream::class, $container->getDefinition('stream_wrapper.temporary')->getClass());
    self::assertFalse($container->hasDefinition('stream_wrapper.private'));
  }

  /**
   * A bound scheme's definition is re-pointed, tag and id preserved.
   */
  public function testBoundPublicSchemeIsRepointed(): void {
    new Settings([
      'hash_salt' => 'test',
      'flysystem' => [
        'public' => ['driver' => 'local', 'config' => ['root' => '/tmp/flysystem-test-public']],
      ],
    ]);
    $container = $this->containerWithCoreWrappers();
    (new FlysystemServiceProvider())->register($container);

    $definition = $container->getDefinition('stream_wrapper.public');
    self::assertNotSame(PublicStream::class, $definition->getClass(), 'The bound public scheme must be served by a Flysystem wrapper class.');
    self::assertSame('public', $definition->getTag('stream_wrapper')[0]['scheme'] ?? NULL, 'Re-pointing must preserve the scheme tag.');
    self::assertTrue(class_exists($definition->getClass()), 'The registered class must exist at build time.');
    self::assertTrue((new \ReflectionClass($definition->getClass()))->isInstantiable(), 'PHP instantiates wrappers without dependency injection; the class must be concrete.');
    // Unbound schemes are untouched.
    self::assertSame(TemporaryStream::class, $container->getDefinition('stream_wrapper.temporary')->getClass());
  }

  /**
   * The private wrapper can be introduced even when core never defined it.
   *
   * Core registers its private wrapper conditionally on a private file
   * path; a settings binding for private must work without that path, so
   * the provider adds the definition itself when it is missing.
   */
  public function testBoundPrivateSchemeIntroducesMissingDefinition(): void {
    new Settings([
      'hash_salt' => 'test',
      'flysystem' => [
        'private' => ['driver' => 'local', 'config' => ['root' => '/tmp/flysystem-test-private']],
      ],
    ]);
    $container = $this->containerWithCoreWrappers();
    (new FlysystemServiceProvider())->register($container);

    self::assertTrue($container->hasDefinition('stream_wrapper.private'));
    $definition = $container->getDefinition('stream_wrapper.private');
    self::assertNotSame(PrivateStream::class, $definition->getClass());
    self::assertSame('private', $definition->getTag('stream_wrapper')[0]['scheme'] ?? NULL);
  }

  /**
   * A disabled binding resolves to core-native: no re-point.
   */
  public function testDisabledBindingStaysCoreNative(): void {
    new Settings([
      'hash_salt' => 'test',
      'flysystem' => [
        'public' => ['driver' => 'local', 'disabled' => TRUE],
      ],
    ]);
    $container = $this->containerWithCoreWrappers();
    (new FlysystemServiceProvider())->register($container);

    self::assertSame(PublicStream::class, $container->getDefinition('stream_wrapper.public')->getClass());
  }

  /**
   * An unknown plugin id still re-points: build time must not know it.
   *
   * Deciding that 'nosuchplugin' is unknown would be validation, which
   * belongs to first use, not container build. Registering the wrapper is
   * the silent half; the failure is for whoever touches the scheme.
   */
  public function testUnknownDriverStillRepointsSilently(): void {
    new Settings([
      'hash_salt' => 'test',
      'flysystem' => [
        'public' => ['driver' => 'nosuchplugin'],
      ],
    ]);
    $container = $this->containerWithCoreWrappers();
    (new FlysystemServiceProvider())->register($container);

    self::assertNotSame(PublicStream::class, $container->getDefinition('stream_wrapper.public')->getClass());
  }

  /**
   * A layer that cannot be read at all leaves the site core-native, silent.
   *
   * The rejected two-section write shape throws from the settings source;
   * the provider catches every configuration failure of the layer and
   * registers nothing for the affected scheme.
   */
  public function testUnreadableLayerIsSkippedSilently(): void {
    new Settings([
      'hash_salt' => 'test',
      'flysystem' => [
        'adapters' => ['a' => ['plugin' => 'local']],
        'bindings' => ['public' => ['adapter' => 'a']],
      ],
    ]);
    $container = $this->containerWithCoreWrappers();
    (new FlysystemServiceProvider())->register($container);

    self::assertSame(PublicStream::class, $container->getDefinition('stream_wrapper.public')->getClass());
    self::assertSame(TemporaryStream::class, $container->getDefinition('stream_wrapper.temporary')->getClass());
  }

  /**
   * An entry without a usable driver string is not a binding, skipped.
   */
  public function testEntryWithoutDriverIsSkipped(): void {
    new Settings([
      'hash_salt' => 'test',
      'flysystem' => [
        'public' => ['config' => ['root' => '/tmp/flysystem-test-public']],
      ],
    ]);
    $container = $this->containerWithCoreWrappers();
    (new FlysystemServiceProvider())->register($container);

    self::assertSame(PublicStream::class, $container->getDefinition('stream_wrapper.public')->getClass());
  }

  /**
   * Schemes outside the core storage set are not registered here.
   *
   * Registering brand-new schemes is an administrative concern arriving
   * later; this row owns only the replacement of core's storage schemes.
   */
  public function testNewSchemesAreNotRegisteredYet(): void {
    new Settings([
      'hash_salt' => 'test',
      'flysystem' => [
        'backup' => ['driver' => 'local', 'config' => ['root' => '/tmp/flysystem-test-backup']],
      ],
    ]);
    $container = $this->containerWithCoreWrappers();
    (new FlysystemServiceProvider())->register($container);

    self::assertFalse($container->hasDefinition('stream_wrapper.backup'));
  }

  /**
   * The registered classes report core's own type constants per scheme.
   *
   * UI filters and enumeration depend on the type bits; a bound scheme
   * must present exactly what its core-native counterpart does.
   */
  public function testRegisteredClassesReportCoreTypeConstants(): void {
    new Settings([
      'hash_salt' => 'test',
      'flysystem' => [
        'public' => ['driver' => 'local', 'config' => ['root' => '/tmp/flysystem-test-public']],
        'private' => ['driver' => 'local', 'config' => ['root' => '/tmp/flysystem-test-private']],
        'temporary' => ['driver' => 'local', 'config' => ['root' => '/tmp/flysystem-test-temporary']],
      ],
    ]);
    $container = $this->containerWithCoreWrappers();
    (new FlysystemServiceProvider())->register($container);

    $public_class = $container->getDefinition('stream_wrapper.public')->getClass();
    $private_class = $container->getDefinition('stream_wrapper.private')->getClass();
    $temporary_class = $container->getDefinition('stream_wrapper.temporary')->getClass();

    self::assertSame(PublicStream::getType(), $public_class::getType());
    self::assertSame(PrivateStream::getType(), $private_class::getType());
    self::assertSame(TemporaryStream::getType(), $temporary_class::getType());
  }

}
