<?php

declare(strict_types=1);

namespace Drupal\flysystem\Tests\Kernel\StreamWrapper;

use Drupal\Core\StreamWrapper\PrivateStream;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\StreamWrapper\TemporaryStream;
use Drupal\flysystem\Exception\FlysystemConfigExceptionInterface;
use Drupal\flysystem\StreamWrapper\FlysystemPublicStream;
use Drupal\flysystem\StreamWrapper\FlysystemTemporaryStream;
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
 * silently, and its typed error surfaces only when a file operation —
 * through PHP's own stream functions — actually reaches the scheme.
 */
#[Group('flysystem')]
#[RunTestsInSeparateProcesses]
final class WrapperRegistrationCoexistenceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['flysystem'];

  /**
   * Applies a flysystem settings layer and rebuilds the container.
   *
   * The rebuild re-runs service providers, so wrapper definitions for
   * bound schemes are re-pointed at build time exactly as they would be
   * on a real site; the explicit wrapper registration mirrors the request
   * step that registers wrappers from the final class map.
   *
   * @param array $bindings
   *   The value for the flysystem settings key.
   */
  protected function rebuildWithBindings(array $bindings): void {
    $this->setSetting('flysystem', $bindings);
    $kernel = $this->container->get('kernel');
    $this->container = $kernel->rebuildContainer();
    // PHP instantiates stream wrappers without dependency injection; the
    // global container must be current for wrappers that reach services.
    \Drupal::setContainer($this->container);
    $this->container->get('stream_wrapper_manager')->register();
  }

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
    $this->rebuildWithBindings([
      'public' => ['driver' => 'local', 'config' => ['root' => 'tmp-flysystem-kernel-public']],
    ]);
    $manager = $this->container->get('stream_wrapper_manager');

    self::assertSame(FlysystemPublicStream::class, $manager->getClass('public'));
    self::assertInstanceOf(FlysystemPublicStream::class, $manager->getViaUri('public://anything.txt'));
    // Untouched neighbors.
    self::assertSame(TemporaryStream::class, $manager->getClass('temporary'));
  }

  /**
   * A bound public scheme names, describes, and URLs itself like core's.
   *
   * The file field configuration UI reads exactly these from the wrapper;
   * the replacement must present the same words and the same download
   * address as the wrapper it replaced, or the site builder loses
   * information mid-swap.
   */
  public function testBoundSchemePresentsCoreNamingAndUrls(): void {
    $this->rebuildWithBindings([
      'public' => ['driver' => 'local', 'config' => ['root' => 'sites/default/files']],
    ]);
    $manager = $this->container->get('stream_wrapper_manager');

    $bound = $manager->getViaUri('public://example.jpg');
    self::assertSame('Public files', (string) $bound->getName());
    self::assertSame('Public local files served by the webserver.', (string) $bound->getDescription());

    $native = new PublicStream();
    $native->setUri('public://example.jpg');
    self::assertSame($native->getExternalUrl(), $bound->getExternalUrl(), 'A bound public file must keep the URL the webserver serves it at.');
  }

  /**
   * A binding may introduce the private wrapper where core defined none.
   */
  public function testPrivateSchemeBoundWithoutCorePrivatePath(): void {
    $this->rebuildWithBindings([
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
    $this->rebuildWithBindings([
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
   * refreshes the enumeration filter caches; the compile-time class map
   * read by getClass() is deliberately outside its reach, which is why
   * administrable bindings land in the filters that drive the request.
   */
  public function testLateReRegistrationReplacesBuildTimeWrapper(): void {
    $this->rebuildWithBindings([
      'public' => ['driver' => 'local', 'config' => ['root' => 'tmp-flysystem-kernel-public']],
    ]);
    $manager = $this->container->get('stream_wrapper_manager');
    self::assertSame(FlysystemPublicStream::class, $manager->getClass('public'));

    $manager->registerWrapper('public', PrivateStream::class, PublicStream::getType());

    self::assertSame(PrivateStream::class, $manager->getWrappers(StreamWrapperInterface::ALL)['public']['class'] ?? NULL, 'The filter caches must describe the wrapper PHP actually dispatches to.');
    self::assertArrayHasKey('public', $manager->getWrappers(StreamWrapperInterface::WRITE_VISIBLE));
  }

  /**
   * A binding with an unknown plugin builds silently and fails on touch.
   *
   * Container build must not resolve or validate anything: the site
   * boots, the wrapper is registered, and the typed configuration error
   * surfaces only when a file operation reaches the scheme — including
   * through PHP's own stream functions, which is the proof that dispatch
   * arrived at this module's registered class and nowhere else.
   */
  public function testBrokenBindingBuildsSilentlyAndErrorsOnTouch(): void {
    $this->rebuildWithBindings([
      'public' => ['driver' => 'nosuchplugin'],
    ]);
    $manager = $this->container->get('stream_wrapper_manager');
    self::assertSame(FlysystemPublicStream::class, $manager->getClass('public'), 'Registration happened without consulting the binding details.');

    $exception = NULL;
    try {
      fopen('public://x.txt', 'r');
    }
    catch (FlysystemConfigExceptionInterface $e) {
      $exception = $e;
    }
    self::assertInstanceOf(FlysystemConfigExceptionInterface::class, $exception, 'Reaching the broken scheme through PHP stream functions must raise its typed error, not a silent core-native fallback.');
  }

  /**
   * Reverting the settings layer returns the scheme to core-native.
   */
  public function testUnbindingRestoresCoreClasses(): void {
    $this->rebuildWithBindings([
      'public' => ['driver' => 'local', 'config' => ['root' => 'tmp-flysystem-kernel-public']],
    ]);
    self::assertSame(FlysystemPublicStream::class, $this->container->get('stream_wrapper_manager')->getClass('public'));

    $this->rebuildWithBindings([]);
    self::assertSame(PublicStream::class, $this->container->get('stream_wrapper_manager')->getClass('public'));
  }

}
