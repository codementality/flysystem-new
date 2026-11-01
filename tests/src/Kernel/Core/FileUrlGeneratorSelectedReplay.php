<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Kernel\Core;

use Drupal\Core\File\Exception\InvalidStreamWrapperException;
use Drupal\Core\File\FileUrlGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Replayed from core's FileUrlGeneratorTest, selection-parameterized.
 *
 * Only the URL-generation legs and the unknown-scheme legs are copied.
 * The unknown-scheme legs are core-native and stay verbatim as the
 * parity floor. The data-provider leg keeps core's rows that resolve
 * without any stream wrapper (schemaless, relative, and external
 * paths); core's rows for its own dummy-external-readonly wrapper are
 * dropped because that wrapper belongs to a test module this replay
 * suite does not enable, and the alter-hook legs of the corpus
 * (CDN / root-relative / protocol-relative rewrites) are dropped for
 * the same reason: their assertions name that module's enum values.
 *
 * Where core asserted an exact external URL built from a core wrapper
 * path, the expectation here is what the subject wrapper's
 * getExternalUrl() answer must carry — the URI's target path. The
 * stub answers the empty string, so these legs fail red naming
 * getExternalUrl; the full absolute URL cannot be pinned before the
 * binding's external base is implemented. In the schema leg the
 * wrapper's own answer is asserted first, because core's generator
 * call raises (rather than returns) on the empty stub answer.
 */
#[CoversClass(FileUrlGenerator::class)]
abstract class FileUrlGeneratorSelectedReplay extends FileReplayBase {

  /**
   * The file URL generator under test.
   *
   * @var \Drupal\Core\File\FileUrlGenerator
   */
  protected $fileUrlGenerator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->fileUrlGenerator = $this->container->get('file_url_generator');
  }

  /**
   * Tests missing stream handler.
   */
  public function testGenerateMissingStreamWrapper(): void {
    $this->assertDispatchIsLive();
    $this->expectException(InvalidStreamWrapperException::class);
    $this->fileUrlGenerator->generate("foo://bar");
  }

  /**
   * Tests missing stream handler.
   */
  public function testGenerateStringMissingStreamWrapper(): void {
    $this->assertDispatchIsLive();
    $this->expectException(InvalidStreamWrapperException::class);
    $this->fileUrlGenerator->generateString("foo://bar");
  }

  /**
   * Tests missing stream handler.
   */
  public function testGenerateAbsoluteStringMissingStreamWrapper(): void {
    $this->assertDispatchIsLive();
    $this->expectException(InvalidStreamWrapperException::class);
    $this->fileUrlGenerator->generateAbsoluteString("foo://bar");
  }

  /**
   * Tests generate u r i.
   */
  #[DataProvider('providerGenerateURI')]
  // phpcs:ignore Drupal.NamingConventions.ValidFunctionName.ScopeNotCamelCaps -- The name is copied verbatim from core's corpus.
  public function testGenerateURI($filepath, $expected): void {
    $this->assertDispatchIsLive();

    // Create a mock Request for transformRelative().
    $request = Request::create($GLOBALS['base_url']);
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
    \Drupal::setContainer($this->container);

    // No schema file.
    $url = $this->fileUrlGenerator->generate($filepath);
    $this->assertEquals($expected, $url->toUriString());
  }

  /**
   * Tests generate u r i with schema.
   */
  // phpcs:ignore Drupal.NamingConventions.ValidFunctionName.ScopeNotCamelCaps -- The name is copied verbatim from core's corpus.
  public function testGenerateURIWithSchema(): void {
    $this->assertDispatchIsLive();

    // Create a mock Request for transformRelative().
    $request = Request::create($GLOBALS['base_url']);
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
    \Drupal::setContainer($this->container);

    $wrapper = $this->container->get('stream_wrapper_manager')->getViaUri($this->uri('path/to/file.png'));
    $this->assertNotSame('', $wrapper->getExternalUrl(), 'The wrapper must answer getExternalUrl() for a URI of the scheme under test; the empty stub answer cannot produce a file URL.');
    $url = $this->fileUrlGenerator->generate($this->uri('path/to/file.png'));
    $this->assertStringEndsWith('/path/to/file.png', $url->toUriString(), 'URL generation for a file under the scheme under test must carry the file target through the wrapper\'s getExternalUrl() answer.');
  }

  /**
   * Tests generate absolute string with relative URL.
   */
  // phpcs:ignore Drupal.NamingConventions.ValidFunctionName.ScopeNotCamelCaps -- The name is copied verbatim from core's corpus.
  public function testRelativeFileURL(): void {
    $this->assertDispatchIsLive();

    // Create a mock Request for transformRelative().
    $request = Request::create($GLOBALS['base_url']);
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
    \Drupal::setContainer($this->container);

    // Shipped file. Core-native leg: parity floor.
    $filepath = 'core/assets/vendor/jquery/jquery.min.js';
    $url = $this->fileUrlGenerator->generateAbsoluteString($filepath);
    $this->assertSame(base_path() . $filepath, $this->fileUrlGenerator->transformRelative($url));

    // Managed file, now under the scheme under test.
    $uri = $this->createUri();
    $url = $this->fileUrlGenerator->generateAbsoluteString($uri);
    $this->assertStringEndsWith('/' . rawurlencode(basename($uri)), $url, 'The absolute URL for a file under the scheme under test must be the wrapper\'s getExternalUrl() answer, carrying the file target.');
  }

  /**
   * An upload-shaped name is raw in storage and encoded in the URL.
   *
   * The two layers have opposite conventions and both are load-bearing:
   * managed uploads require an extension the platform recognizes, the
   * storage key is exactly what was written (no percent-decoding on
   * read), and percent-encoding happens when the name crosses into a
   * URL. This leg pins all three from the production upload path.
   */
  public function testUploadFormNameIsRawInStorageAndEncodedInUrls(): void {
    $this->assertDispatchIsLive();

    // Create a mock Request for transformRelative().
    $request = Request::create($GLOBALS['base_url']);
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
    \Drupal::setContainer($this->container);

    $uri = $this->createUri(NULL, 'encoded-contract');
    $this->assertSame(
      'encoded-contract',
      file_get_contents($uri),
      'The storage key is the raw name — reading through it must return the bytes without any decoding step.'
    );

    $string = $this->fileUrlGenerator->generateString($uri);
    $this->assertStringContainsString(
      rawurlencode(basename($uri)),
      $string,
      'The URL layer must percent-encode the international name; the wrapper\'s getExternalUrl() answer feeds it.'
    );
    $this->assertStringNotContainsString(
      basename($uri),
      $string,
      'A raw unencoded international name must not appear in the generated URL.'
    );
  }

  /**
   * Data provider.
   */
  // phpcs:ignore Drupal.NamingConventions.ValidFunctionName.ScopeNotCamelCaps -- The name is copied verbatim from core's corpus.
  public static function providerGenerateURI(): array {
    return [
      'schemaless' =>
        [
          '//core/assets/vendor/jquery/jquery.min.js',
          '//core/assets/vendor/jquery/jquery.min.js',
        ],
      'query string' =>
        [
          '//core/assets/vendor/jquery/jquery.min.js?foo',
          '//core/assets/vendor/jquery/jquery.min.js?foo',
        ],
      'query string and hashes' =>
        [
          '//core/assets/vendor/jquery/jquery.min.js?foo=bar#whizz',
          '//core/assets/vendor/jquery/jquery.min.js?foo=bar#whizz',
        ],
      'hashes' =>
        [
          '//core/assets/vendor/jquery/jquery.min.js#whizz',
          '//core/assets/vendor/jquery/jquery.min.js#whizz',
        ],
      'root-relative' =>
        [
          '/core/assets/vendor/jquery/jquery.min.js',
          'base:/core/assets/vendor/jquery/jquery.min.js',
        ],
      'relative' =>
        [
          'core/assets/vendor/jquery/jquery.min.js',
          'base:core/assets/vendor/jquery/jquery.min.js',
        ],
      'external' =>
        [
          'https://www.example.com/core/assets/vendor/jquery/jquery.min.js',
          'https://www.example.com/core/assets/vendor/jquery/jquery.min.js',
        ],
    ];
  }

}
