<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Kernel\Core;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\KernelTests\KernelTestBase;

/**
 * Shared harness for the replayed core File corpus.
 *
 * The classes in this directory are parameterized copies of Drupal
 * core's own kernel tests for file and stream-wrapper behavior, with
 * one design correction: every URI is built from the scheme under
 * test, never hardcoded to core's public:// — a copied body asserting
 * against a scheme it does not exercise passes vacuously, and vacuous
 * passes are the failure this harness exists to prevent. Host classes
 * differ only in the modules they enable and the driver word their
 * binding carries; the suite body, and therefore the specification,
 * is shared.
 *
 * Registration here is the test-scoped kind core's own file tests
 * have always used — a scheme tagged into the container at build time
 * and registered by the kernel with PHP. No production registration
 * machinery is assumed or exercised.
 */
abstract class FileReplayBase extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'flysystem'];

  /**
   * The scheme every URI in the replayed suites is built from.
   *
   * @var string
   */
  protected string $scheme = 'fsreplay';

  /**
   * The absolute directory the local host's binding roots at.
   *
   * @var string
   */
  protected string $replayRoot = '';

  /**
   * The class registered for the scheme under test.
   *
   * The harness's dispatch-probing subclass of the module's wrapper.
   * Observability lives test-side; the production class carries none.
   *
   * @var string
   */
  protected string $wrapperClass = DispatchProbedStreamWrapper::class;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container
      ->register('stream_wrapper.' . $this->scheme, $this->wrapperClass)
      ->addTag('stream_wrapper', ['scheme' => $this->scheme]);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Core's KernelTestBase overrides the default scheme globally; the
    // replay harness configures explicitly the way core's own file
    // base does, so remove the override before reading configuration.
    unset($GLOBALS['config']['system.file']);
    $this->container->get('config.factory')->getEditable('system.file')
      ->set('default_scheme', 'public')
      ->set('allow_insecure_uploads', FALSE)
      ->set('temporary_maximum_age', 21600)
      ->save();

    $this->replayRoot = $this->siteDirectory . '/replay-root';
    // The binding is ordinary site configuration: the driver word and
    // its config flow through the same resolution path the production
    // wrapper will read them through once storage behavior lands. No
    // test injects an operator into a wrapper.
    $this->setSetting('flysystem', [
      $this->scheme => [
        'driver' => $this->replayDriver(),
        'config' => $this->replayConfig(),
      ],
    ]);
    DispatchProbedStreamWrapper::resetJournal();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    DispatchProbedStreamWrapper::resetJournal();
    parent::tearDown();
  }

  /**
   * The driver word this host binds the replay scheme to.
   */
  abstract protected function replayDriver(): string;

  /**
   * Whether this host's binding declares local-storage traversal rules.
   */
  abstract protected function replayIsLocalStorage(): bool;

  /**
   * The adapter-plugin configuration for this host's binding.
   *
   * @return array
   *   Configuration merged into the plugin instance.
   */
  protected function replayConfig(): array {
    return [];
  }

  /**
   * Builds a URI for the scheme under test from a root-relative path.
   *
   * @param string $path
   *   The path inside the bound root.
   *
   * @return string
   *   The stream URI.
   */
  protected function uri(string $path): string {
    return $this->scheme . '://' . ltrim($path, '/');
  }

  /**
   * The real filesystem path of a replay URI (local hosts only).
   *
   * @param string $path
   *   The path inside the bound root.
   *
   * @return string
   *   The absolute path.
   */
  protected function realPath(string $path): string {
    return $this->replayRoot . '/' . ltrim($path, '/');
  }

  /**
   * Proves the PHP engine routes this scheme to the subject wrapper.
   *
   * Every replay test calls this first: if the journal stayed empty,
   * no assertion later in the method would be exercising the wrapper
   * at all, and any pass after that would be vacuous. The probe is a
   * suppressed fopen, which reaches the wrapper's url_stat/stream_open
   * whether or not those implementations exist yet.
   */
  protected function assertDispatchIsLive(): void {
    self::assertContains(
      $this->scheme,
      stream_get_wrappers(),
      "The replay scheme must be registered with PHP before any leg runs."
    );
    DispatchProbedStreamWrapper::resetJournal();
    @fopen($this->uri('dispatch-probe'), 'r');
    self::assertNotEmpty(
      DispatchProbedStreamWrapper::journal(),
      'The engine must actually dispatch this scheme to the subject wrapper — an empty journal means every later assertion in this test would pass vacuously.'
    );
    DispatchProbedStreamWrapper::resetJournal();
  }

  /**
   * Creates a file under the scheme under test and returns its URI.
   *
   * The default fixture name is an upload-shaped name, not core's
   * original fixture: managed uploads are filtered by extension (the
   * platform's default allow-list is jpg, jpeg, gif, png, txt, and
   * friends — an extensionless name can never be an upload), and the
   * storage key keeps its characters raw — percent-encoding belongs to
   * the URL layer and is pinned by a dedicated leg in the URL generator
   * replay. The international characters are retained from core's
   * fixture so Unicode keys stay under test; spaces are replaced.
   *
   * The write goes through the wrapper — this helper is itself a leg,
   * not a fixture bypass.
   *
   * @param string|null $path
   *   The path inside the bound root; random when NULL.
   * @param string|null $contents
   *   The bytes to write.
   *
   * @return string
   *   The stream URI.
   */
  protected function createUri(?string $path = NULL, ?string $contents = NULL): string {
    $path ??= 'Файл_для_тестирования_' . $this->randomMachineName() . '.txt';
    $uri = $this->uri($path);
    file_put_contents($uri, $contents ?? "file_put_contents() doesn't seem to appreciate empty strings so let's put in some data.");
    self::assertFileExists($uri);
    return $uri;
  }

  /**
   * An upload-shaped random file name (approved extension included).
   *
   * Managed upload filtering recognizes names like this; the copied
   * suites use it for any fixture that simulates an uploaded file.
   */
  protected function uploadName(): string {
    return $this->randomMachineName() . '.txt';
  }

  /**
   * Creates a directory under the scheme under test via file_system.
   *
   * @param string|null $path
   *   The directory path; random when NULL.
   *
   * @return string
   *   The stream URI.
   */
  protected function createDirectory(?string $path = NULL): string {
    $uri = $this->uri($path ?? $this->randomMachineName());
    self::assertTrue($this->container->get('file_system')->mkdir($uri));
    self::assertDirectoryExists($uri);
    return $uri;
  }

  /**
   * Asserts a written file carries the expected unix mode (local only).
   *
   * @param string $uri
   *   The stream URI.
   * @param int $expected_mode
   *   The octal mode expected.
   * @param string|null $message
   *   Optional message.
   */
  public function assertFilePermissions(string $uri, int $expected_mode, ?string $message = NULL): void {
    clearstatcache(TRUE, $uri);
    $actual_mode = fileperms($uri) & 0777;
    if (!isset($message)) {
      $message = sprintf('Expected file permission to be %s, actually were %s.', decoct($actual_mode), decoct($expected_mode));
    }
    self::assertEquals($expected_mode, $actual_mode, $message);
  }

  /**
   * Asserts a directory carries the expected unix mode (local only).
   *
   * @param string $uri
   *   The stream URI.
   * @param int $expected_mode
   *   The octal mode expected.
   * @param string|null $message
   *   Optional message.
   */
  public function assertDirectoryPermissions(string $uri, int $expected_mode, ?string $message = NULL): void {
    clearstatcache(TRUE, $uri);
    $actual_mode = fileperms($uri) & 0777;
    if (!isset($message)) {
      $message = sprintf('Expected directory permission to be %s, actually were %s.', decoct($expected_mode), decoct($actual_mode));
    }
    self::assertEquals($expected_mode, $actual_mode, $message);
  }

  /**
   * The on-disk path a binding would report for one sample file.
   *
   * Core's exception messages sometimes embed the resolved path of a
   * stream URI. That is only a truth to assert against when this
   * host's binding names an on-disk location; for a non-local binding
   * the wrapper's realpath answer is the wrapper's own, not a path on
   * disk, so NULL tells the suite to assert the exception class and
   * the URI-containing prefix of the message instead. Local hosts
   * override this to return the real path of the sample file.
   *
   * @param string $path
   *   The path inside the bound root.
   *
   * @return string|null
   *   The on-disk path, or NULL when the binding has none.
   */
  protected function replaySampleRealPath(string $path): ?string {
    return NULL;
  }

}
