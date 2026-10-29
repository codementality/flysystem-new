<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Kernel\StreamWrapper;

use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\flysystem\Factory\OperatorFactory;
use Drupal\Core\StreamWrapper\PrivateStream;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\StreamWrapper\TemporaryStream;
use Drupal\flysystem\StreamWrapper\FlysystemPrivateStream;
use Drupal\flysystem\StreamWrapper\FlysystemPublicStream;
use Drupal\flysystem\StreamWrapper\FlysystemTemporaryStream;
use Drupal\Tests\flysystem\Traits\FlysystemBootTestTrait;
use Drupal\Tests\flysystem\Traits\FlysystemKernelTestTrait;
use Drupal\KernelTests\KernelTestBase;
use League\Flysystem\UrlGeneration\PublicUrlGenerator;
use League\Flysystem\UrlGeneration\TemporaryUrlGenerator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Drupal-facing half of the seam: names, enumerations, URLs.
 *
 * The storage swap must be invisible where the product talks to the
 * scheme: a bound public file keeps the exact URL the webserver served
 * it at before binding, bound schemes appear in core's own
 * write-visible enumerations (what file field widgets and upload
 * locations list) with core's wording, the private and temporary schemes
 * stay routed through PHP exactly as they are today, and the URI context
 * a per-instance wrapper carries round-trips untouched. URL generation
 * is proven Drupal-side: the adapter plugin carries neither of the
 * library's URL-generator interfaces, and computing a URL never reaches
 * the storage seam at all.
 */
#[Group('flysystem')]
#[RunTestsInSeparateProcesses]
final class WrapperUrlAndUiSurfaceTest extends KernelTestBase {

  use FlysystemKernelTestTrait;
  use FlysystemBootTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['flysystem'];

  /**
   * A bound public file presents core's name, description, and URL.
   *
   * The comparison is against core's own wrapper instance answering the
   * same URI in the same container state, so parity is exact, not
   * paraphrased: the label strings a site builder reads and the URL the
   * webserver serves the file at do not change when the scheme binds.
   */
  public function testBoundPublicPresentsCoreNamingAndUrl(): void {
    $this->rebuildWithFlysystemBindings([]);
    $native = new PublicStream();
    $native->setUri('public://some/deep/file.jpg');
    $native_name = (string) $native->getName();
    $native_description = (string) $native->getDescription();
    $native_url = $native->getExternalUrl();
    self::assertNotEmpty($native_url, 'The native public wrapper must answer a URL for the comparison to mean anything.');

    $this->rebuildWithFlysystemBindings([
      'public' => ['driver' => 'local', 'config' => ['root' => 'tmp-flysystem-m2-05']],
    ]);
    $bound = $this->container->get('stream_wrapper_manager')->getViaUri('public://some/deep/file.jpg');
    self::assertInstanceOf(FlysystemPublicStream::class, $bound);
    self::assertSame($native_name, (string) $bound->getName());
    self::assertSame($native_description, (string) $bound->getDescription());
    self::assertSame($native_url, $bound->getExternalUrl(), 'A bound public file must keep the URL the webserver serves it at.');
  }

  /**
   * Bound schemes are present in the write-visible enumerations.
   *
   * Core's own enumeration API — the one file field widgets and upload
   * locations read — lists bound schemes under core's labels with this
   * module's classes behind them. The hidden temporary scheme stays out
   * of the write-visible set exactly as core keeps it, and its class is
   * still this module's once bound.
   */
  public function testWriteVisibleEnumerationReadsBoundSchemes(): void {
    $this->rebuildWithFlysystemBindings([
      'public' => ['driver' => 'local', 'config' => ['root' => 'tmp-flysystem-m2-05-public']],
      'private' => ['driver' => 'local', 'config' => ['root' => 'tmp-flysystem-m2-05-private']],
      'temporary' => ['driver' => 'local', 'config' => ['root' => 'tmp-flysystem-m2-05-temp']],
    ]);
    $manager = $this->container->get('stream_wrapper_manager');

    $names = $manager->getNames(StreamWrapperInterface::WRITE_VISIBLE);
    self::assertArrayHasKey('public', $names, 'The bound public scheme must stay selectable wherever core lists writable visible schemes.');
    self::assertArrayHasKey('private', $names, 'A bound private scheme becomes selectable in core forms without any core patch.');
    self::assertSame('Public files', (string) $names['public']);
    self::assertSame('Private files', (string) $names['private']);
    self::assertArrayNotHasKey('temporary', $names, 'The temporary scheme stays hidden from write-visible lists, as today.');

    $wrappers = $manager->getWrappers(StreamWrapperInterface::WRITE_VISIBLE);
    self::assertSame(FlysystemPublicStream::class, $wrappers['public']['class']);
    self::assertSame(FlysystemPrivateStream::class, $wrappers['private']['class']);
    self::assertSame(FlysystemTemporaryStream::class, $manager->getClass('temporary'), 'The bound temporary scheme dispatches to this module even though it stays hidden from the visible lists.');
  }

  /**
   * The file URL generator answers identically bound and unbound.
   *
   * Plain and image-style-derivative URIs both go through the service
   * that file displays and image styles use; the strings must match
   * core-native exactly, in relative and absolute form.
   */
  public function testFileUrlGeneratorProducesIdenticalUrlsBoundAndUnbound(): void {
    $uris = [
      'public://photo.jpg',
      'public://styles/large/public/photo.jpg',
    ];

    $this->rebuildWithFlysystemBindings([]);
    $generator = $this->container->get('file_url_generator');
    $native = [];
    foreach ($uris as $uri) {
      $native[] = [$generator->generateString($uri), $generator->generateAbsoluteString($uri)];
    }

    $this->rebuildWithFlysystemBindings([
      'public' => ['driver' => 'local', 'config' => ['root' => 'tmp-flysystem-m2-05']],
    ]);
    $generator = $this->container->get('file_url_generator');
    foreach ($uris as $index => $uri) {
      self::assertSame($native[$index][0], $generator->generateString($uri), "Relative URL changed for {$uri} when the scheme bound.");
      self::assertSame($native[$index][1], $generator->generateAbsoluteString($uri), "Absolute URL changed for {$uri} when the scheme bound.");
    }
  }

  /**
   * The per-instance URI context round-trips untouched.
   *
   * Every core code path reads the URI back off the wrapper; nothing at
   * this seam normalizes on the way in (path policy belongs to the
   * operator's guard and the consumers' normalizer, proven elsewhere).
   */
  public function testSetUriGetUriRoundTrip(): void {
    $this->rebuildWithFlysystemBindings([
      'public' => ['driver' => 'local', 'config' => ['root' => 'tmp-flysystem-m2-05']],
    ]);
    $wrapper = new FlysystemPublicStream();
    foreach (['public://plain/file.txt', 'public://with space/file.txt', 'public://a/../b.txt'] as $uri) {
      $wrapper->setUri($uri);
      self::assertSame($uri, $wrapper->getUri(), 'The URI context must come back exactly as it went in.');
    }
  }

  /**
   * Computing a URL never reaches the storage seam.
   *
   * URL generation is Drupal-side at this boundary: the factory must
   * not be consulted to answer a URL — with a double that would fail on
   * any call, the wrapper and the generator still answer normally.
   */
  public function testUrlGenerationNeverConsultsStorage(): void {
    $this->rebuildWithFlysystemBindings([
      'public' => ['driver' => 'local', 'config' => ['root' => 'tmp-flysystem-m2-05']],
    ]);
    $untouchable = $this->createMock(OperatorFactory::class);
    $untouchable->expects(self::never())->method('operatorFor');
    $untouchable->expects(self::never())->method('definitionFor');
    $this->container->set('flysystem.operator_factory', $untouchable);
    \Drupal::setContainer($this->container);

    $wrapper = new FlysystemPublicStream();
    $wrapper->setUri('public://url-check.txt');
    self::assertNotEmpty($wrapper->getExternalUrl(), 'The public URL answers from path data alone.');
    self::assertNotEmpty($this->container->get('file_url_generator')->generateString('public://url-check.txt'));
  }

  /**
   * The adapter plugin carries no URL-generator capability.
   *
   * The library's own URL interfaces would delegate external URLs to
   * the backend; the shipped local plugin must implement neither, so
   * there is no second path by which URLs could change when bound.
   */
  public function testLocalAdapterCarriesNoUrlGeneratorInterfaces(): void {
    $plugin = $this->container->get('plugin.manager.flysystem_adapter')
      ->createInstance('local', ['root' => \Drupal::root() . '/tmp-flysystem-m2-05']);
    self::assertNotInstanceOf(PublicUrlGenerator::class, $plugin, 'The adapter plugin must not claim public URL generation.');
    self::assertNotInstanceOf(TemporaryUrlGenerator::class, $plugin, 'The adapter plugin must not claim temporary URL generation.');
  }

  /**
   * Private and temporary wrappers mirror core's routed-URL construction.
   *
   * Both schemes stay served through PHP regardless of storage: the
   * classes are this module's once bound, the labels a site builder
   * reads match core's exactly, and the URL each wrapper hands back is
   * byte-identical to the one core's own wrapper builds for the same
   * target — both go through the same system routes. The routes are
   * supplied by a provider stub answering the two system paths, so the
   * comparison exercises the real URL generation machinery.
   */
  public function testPrivateAndTemporaryKeepRoutedUrlBehavior(): void {
    $this->rebuildWithFlysystemBindings([]);
    $this->installSystemRoutes();
    $native_private = new PrivateStream();
    $native_private->setUri('private://secret.txt');
    $native_temporary = new TemporaryStream();
    $native_temporary->setUri('temporary://staged.txt');
    $native_private_name = (string) $native_private->getName();
    $native_temporary_description = (string) $native_temporary->getDescription();
    $native_private_url = $native_private->getExternalUrl();
    $native_temporary_url = $native_temporary->getExternalUrl();

    $this->rebuildWithFlysystemBindings([
      'private' => ['driver' => 'local', 'config' => ['root' => 'tmp-flysystem-m2-05-private']],
      'temporary' => ['driver' => 'local', 'config' => ['root' => 'tmp-flysystem-m2-05-temp']],
    ]);
    $this->installSystemRoutes();
    $manager = $this->container->get('stream_wrapper_manager');
    self::assertSame(FlysystemPrivateStream::class, $manager->getClass('private'));
    self::assertSame(FlysystemTemporaryStream::class, $manager->getClass('temporary'));

    $bound_private = new FlysystemPrivateStream();
    $bound_private->setUri('private://secret.txt');
    $bound_temporary = new FlysystemTemporaryStream();
    $bound_temporary->setUri('temporary://staged.txt');

    self::assertSame($native_private_name, (string) $bound_private->getName(), 'Labels match core for the private scheme.');
    self::assertSame($native_temporary_description, (string) $bound_temporary->getDescription(), 'Labels match core for the temporary scheme.');
    self::assertSame($native_private_url, $bound_private->getExternalUrl(), 'The private download URL is core-routed, unchanged by binding.');
    self::assertSame($native_temporary_url, $bound_temporary->getExternalUrl(), 'The temporary staging URL is core-routed, unchanged by binding.');
  }

  /**
   * Swaps in a route provider answering the two file-serving routes.
   *
   * The kernel container carries no route table; URL generation only
   * needs the route definitions core's own wrappers reference, so a
   * stub provider supplying exactly those two routes lets core's
   * wrappers and this module's be compared through the real generator.
   */
  private function installSystemRoutes(): void {
    $provider = new class implements RouteProviderInterface {

      /**
       * {@inheritdoc}
       */
      public function getRouteCollectionForRequest(Request $request): RouteCollection {
        return new RouteCollection();
      }

      /**
       * {@inheritdoc}
       */
      public function getRouteByName($name): Route {
        return match ($name) {
          'system.private_file_download' => new Route('/system/files/{filepath}'),
          'system.temporary' => new Route('/system/temporary'),
          default => throw new RouteNotFoundException("Route {$name} does not exist."),
        };
      }

      /**
       * {@inheritdoc}
       */
      public function getRoutesByNames($names): iterable {
        return [];
      }

      /**
       * {@inheritdoc}
       */
      public function getRoutesByPattern($pattern): RouteCollection {
        return new RouteCollection();
      }

      /**
       * {@inheritdoc}
       */
      public function getAllRoutes(): RouteCollection {
        return new RouteCollection();
      }

      /**
       * {@inheritdoc}
       */
      public function reset(): void {
      }

      /**
       * {@inheritdoc}
       */
      public function getRouteAliases(string $route_name): iterable {
        return [];
      }

    };
    $this->container->set('router.route_provider', $provider);
  }

}
