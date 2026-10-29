<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Kernel\StreamWrapper;

use Drupal\Core\StreamWrapper\PrivateStream;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\StreamWrapper\TemporaryStream;
use Drupal\flysystem\StreamWrapper\FlysystemPublicStream;
use Drupal\flysystem\StreamWrapper\FlysystemTemporaryStream;
use Drupal\Tests\flysystem\Traits\FlysystemBootTestTrait;
use Drupal\Tests\flysystem\Traits\FlysystemKernelTestTrait;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests wrapper registration and coexistence on a booted container.
 *
 * Builds the container with and without flysystem bindings in settings and
 * inspects what the stream wrapper machinery ends up serving per scheme:
 * bound schemes are re-pointed at this module's wrapper classes, unbound
 * schemes keep core's own classes untouched, and the type bits every UI
 * filter reads survive the swap. Configuration is never consulted at
   * container build time: a binding that would fail validation still builds
   * silently, and a file operation that reaches it fails as a logged
   * refusal through PHP's own stream functions.
 */
#[Group('flysystem')]
#[RunTestsInSeparateProcesses]
final class WrapperRegistrationCoexistenceTest extends KernelTestBase {

  use FlysystemKernelTestTrait;
  use FlysystemBootTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['flysystem'];

  /**
   * With no bindings at all, every scheme stays on core's own classes.
   */
  public function testUnboundSiteIsByteIdenticalToCoreNative(): void {
    $manager = $this->container->get('stream_wrapper_manager');

    self::assertSame(PublicStream::class, $manager->getClass('public'));
    self::assertSame(TemporaryStream::class, $manager->getClass('temporary'));
    self::assertFalse($manager->getClass('private'), 'Without a private file path core registers no private wrapper, and neither do we.');

    // Raw stream behavior on an unbound scheme is core's own.
    $uri = 'public://native-check.txt';
    self::assertNotFalse(file_put_contents($uri, 'native'));
    self::assertSame('native', file_get_contents($uri));
    @unlink($uri);
  }

  /**
   * A bound scheme's class map entry is this module's wrapper class.
   */
  public function testBoundSchemeIsServedByTheFlysystemWrapper(): void {
    $this->rebuildWithFlysystemBindings([
      'public' => ['driver' => 'local', 'config' => ['root' => 'tmp-flysystem-kernel-public']],
    ]);
    $manager = $this->container->get('stream_wrapper_manager');

    self::assertSame(FlysystemPublicStream::class, $manager->getClass('public'));
    self::assertInstanceOf(FlysystemPublicStream::class, $manager->getViaUri('public://anything.txt'));
    // Untouched neighbors.
    self::assertSame(TemporaryStream::class, $manager->getClass('temporary'));
  }

  /**
   * A binding may introduce the private wrapper where core defined none.
   */
  public function testPrivateSchemeBoundWithoutCorePrivatePath(): void {
    $this->rebuildWithFlysystemBindings([
      'private' => ['driver' => 'local', 'config' => ['root' => 'tmp-flysystem-kernel-private']],
    ]);
    $manager = $this->container->get('stream_wrapper_manager');

    self::assertNotSame(PrivateStream::class, $manager->getClass('private'));
    self::assertTrue(in_array('private', stream_get_wrappers(), TRUE), 'A bound private scheme must be registered with PHP even when no private file path is configured.');
  }

  /**
   * Type bits a bound scheme reports match core's scheme exactly.
   */
  public function testTypeConstantsSurviveTheSwap(): void {
    $this->rebuildWithFlysystemBindings([
      'public' => ['driver' => 'local', 'config' => ['root' => 'tmp-flysystem-kernel-public']],
      'temporary' => ['driver' => 'local', 'config' => ['root' => 'tmp-flysystem-kernel-temporary']],
    ]);
    $manager = $this->container->get('stream_wrapper_manager');

    self::assertSame(FlysystemPublicStream::class, $manager->getClass('public'));
    self::assertSame(FlysystemTemporaryStream::class, $manager->getClass('temporary'));
    self::assertSame(PublicStream::getType(), $manager->getClass('public')::getType());
    self::assertSame(TemporaryStream::getType(), $manager->getClass('temporary')::getType());
    // Enumeration consequence: the write-visible filter still lists public.
    self::assertArrayHasKey('public', $manager->getWrappers(StreamWrapperInterface::WRITE_VISIBLE));
    // ... and still does not list the hidden temporary scheme.
    self::assertArrayNotHasKey('temporary', $manager->getWrappers(StreamWrapperInterface::WRITE_VISIBLE));
  }

  /**
   * The registration seam is re-entrant: a late swap replaces a class.
   *
   * Request-time registration arrives with administrable bindings; the
   * seam must already accept a re-registration of a build-time scheme.
   * The verified mechanism: registerWrapper() re-registers with PHP and
   * refreshes the enumeration filter caches; the class map frozen at the
   * container build, read by getClass(), is deliberately outside its reach,
   * which is why
   * administrable bindings land in the filters that drive the request.
   */
  public function testLateReRegistrationReplacesBuildTimeWrapper(): void {
    $this->rebuildWithFlysystemBindings([
      'public' => ['driver' => 'local', 'config' => ['root' => 'tmp-flysystem-kernel-public']],
    ]);
    $manager = $this->container->get('stream_wrapper_manager');
    self::assertSame(FlysystemPublicStream::class, $manager->getClass('public'));

    $manager->registerWrapper('public', PrivateStream::class, PublicStream::getType());

    self::assertSame(PrivateStream::class, $manager->getWrappers(StreamWrapperInterface::ALL)['public']['class'] ?? NULL, 'The filter caches must describe the wrapper PHP actually dispatches to.');
    self::assertArrayHasKey('public', $manager->getWrappers(StreamWrapperInterface::WRITE_VISIBLE));
  }

  /**
   * A binding with an unknown plugin builds silently and refuses on touch.
   *
   * Container build must not resolve or validate anything: the site
   * boots, the wrapper is registered, and a file operation reaching the
   * broken scheme — including through PHP's own stream functions, which
   * is the proof that dispatch arrived at this module's registered class
   * and nowhere else — fails as a refusal the caller can see, never as a
   * crash escaping into the stream layer.
   */
  public function testBrokenBindingBuildsSilentlyAndRefusesOnTouch(): void {
    $this->rebuildWithFlysystemBindings([
      'public' => ['driver' => 'nosuchplugin'],
    ]);
    $manager = $this->container->get('stream_wrapper_manager');
    self::assertSame(FlysystemPublicStream::class, $manager->getClass('public'), 'Registration happened without consulting the binding details.');

    $handle = @fopen('public://x.txt', 'r');
    self::assertFalse($handle, 'Reaching the broken scheme through PHP stream functions must fail the operation, not crash it or fall back to core-native storage.');
  }

  /**
   * Reverting the settings layer returns the scheme to core-native.
   */
  public function testUnbindingRestoresCoreClasses(): void {
    $this->rebuildWithFlysystemBindings([
      'public' => ['driver' => 'local', 'config' => ['root' => 'tmp-flysystem-kernel-public']],
    ]);
    self::assertSame(FlysystemPublicStream::class, $this->container->get('stream_wrapper_manager')->getClass('public'));

    $this->rebuildWithFlysystemBindings([]);
    self::assertSame(PublicStream::class, $this->container->get('stream_wrapper_manager')->getClass('public'));
  }

}
