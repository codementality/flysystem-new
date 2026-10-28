<?php

declare(strict_types=1);

namespace Drupal\flysystem\Tests\Kernel\StreamWrapper;

use Drupal\flysystem\StreamWrapper\FlysystemPublicStream;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that a broken binding refuses file operations gracefully.
 *
 * A corrupted binding setting is discovered only when a file operation
 * touches the scheme. The site must stay alive: the operation answers as
 * a failure through PHP's own stream functions, the module's logger
 * channel carries exactly one actionable record naming the scheme, and
 * the verification surface reports the same finding the log recorded —
 * which is the same content the status report shows. No exception from
 * the binding layer may escape a wrapper method into PHP's stream layer,
 * where it would abort the request.
 */
#[Group('flysystem')]
#[RunTestsInSeparateProcesses]
final class BrokenBindingGracefulFailureTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['flysystem'];

  /**
   * The recording channel installed in place of the module's own.
   *
   * @var \Drupal\flysystem\Tests\Kernel\StreamWrapper\RecordingLoggerChannel
   */
  private RecordingLoggerChannel $spy;

  /**
   * Applies a flysystem settings layer, rebuilds, and swaps in the spy.
   *
   * @param array $bindings
   *   The value for the flysystem settings key.
   */
  private function rebuildWithBindings(array $bindings): void {
    $this->setSetting('flysystem', $bindings);
    $kernel = $this->container->get('kernel');
    $this->container = $kernel->rebuildContainer();
    $this->spy = new RecordingLoggerChannel();
    $this->container->set('logger.channel.flysystem', $this->spy);
    // PHP instantiates stream wrappers without dependency injection; the
    // global container must be current for wrappers that reach services.
    \Drupal::setContainer($this->container);
    $this->container->get('stream_wrapper_manager')->register();
  }

  /**
   * Records sent at error severity by the module's channel.
   *
   * @return array<int, array{level: mixed, message: string, context: array}>
   *   The matching records, in order.
   */
  private function errorRecords(): array {
    return array_values(array_filter($this->spy->records, static fn(array $r): bool => $r['level'] === 'error'));
  }

  /**
   * A broken binding fails the open as a refusal with one log record.
   *
   * The proof runs through PHP's own fopen(): the wrapper answers the
   * protocol, so the caller sees the same FALSE it would see from a
   * permission error on a real file — and the request survives to do
   * more work afterwards.
   */
  public function testBrokenBindingFailsOpenWithLoggedRefusal(): void {
    $this->rebuildWithBindings([
      'public' => ['driver' => 'nosuchplugin'],
    ]);
    self::assertSame(FlysystemPublicStream::class, $this->container->get('stream_wrapper_manager')->getClass('public'));

    $handle = @fopen('public://x.txt', 'r');
    self::assertFalse($handle, 'A broken binding must fail the open, not crash it.');

    $records = $this->errorRecords();
    self::assertCount(1, $records, 'The refusal leaves exactly one record, not a crash and not silence.');
    self::assertStringContainsString('nosuchplugin', $records[0]['message'], 'The record names the defect the site builder can act on.');
    self::assertSame('public', $records[0]['context']['scheme'] ?? NULL);

    $written = @file_put_contents('temporary://graceful-check.txt', 'alive');
    self::assertNotFalse($written, 'The request must remain healthy enough for its next file operation.');
    self::assertSame('alive', @file_get_contents('temporary://graceful-check.txt'));
  }

  /**
   * The log record and the verification finding say the same thing.
   *
   * A site builder reading the status report and an operator reading the
   * log must see one problem, described once — the surfaces draw on the
   * same message the binding layer raised.
   */
  public function testVerificationAndLogCarryTheSameFinding(): void {
    $this->rebuildWithBindings([
      'public' => ['driver' => 'nosuchplugin'],
    ]);

    @fopen('public://x.txt', 'r');
    $records = $this->errorRecords();
    self::assertCount(1, $records);

    $verifier = $this->container->get('flysystem.binding_verifier');
    $findings = $verifier->verifyBinding('public');
    self::assertCount(1, $findings, 'The same configuration answers with exactly one finding.');
    self::assertSame('error', $findings[0]['severity']);
    self::assertSame($records[0]['message'], $findings[0]['message'], 'Log and verification must quote the one raised error verbatim.');
  }

  /**
   * A broken scheme does not disable the schemes that are sound.
   *
   * Binding errors are scheme-local: unbound schemes keep serving core's
   * own behavior, and the site still reads and writes files on every
   * path that was not misconfigured.
   */
  public function testBrokenSchemeLeavesOtherSchemesServing(): void {
    $this->rebuildWithBindings([
      'public' => ['driver' => 'nosuchplugin'],
    ]);

    self::assertFalse(@fopen('public://x.txt', 'r'), 'The broken scheme refuses.');
    self::assertSame('core-native', @file_get_contents($this->writeNativeTempFile()), 'Neighboring schemes still serve normally.');
  }

  /**
   * Writes a small file through the unbound temporary scheme.
   *
   * @return string
   *   The URI to read back.
   */
  private function writeNativeTempFile(): string {
    $uri = 'temporary://scheme-local-check.txt';
    file_put_contents($uri, 'core-native');
    return $uri;
  }

}
