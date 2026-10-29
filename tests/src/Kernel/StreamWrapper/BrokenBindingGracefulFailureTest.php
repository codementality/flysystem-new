<?php

declare(strict_types=1);

namespace Drupal\flysystem\Tests\Kernel\StreamWrapper;

use Drupal\flysystem\StreamWrapper\FlysystemPublicStream;
use Drupal\flysystem\Tests\FlysystemBootTestTrait;
use Drupal\flysystem\Tests\FlysystemKernelTestTrait;
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
 * where it would abort the request. Records are read from the watchdog
 * table, so the proof exercises the site's real logging pipeline rather
 * than a stand-in.
 */
#[Group('flysystem')]
#[RunTestsInSeparateProcesses]
final class BrokenBindingGracefulFailureTest extends KernelTestBase {

  use FlysystemKernelTestTrait;
  use FlysystemBootTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['flysystem', 'dblog'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('dblog', ['watchdog']);
  }

  /**
   * Every record the module's channel sent to the logging pipeline.
   *
   * @return array<int, object>
   *   watchdog rows, oldest first.
   */
  private function channelRecords(): array {
    return $this->container->get('database')
      ->select('watchdog', 'w')
      ->fields('w')
      ->condition('w.type', 'flysystem')
      ->orderBy('w.wid')
      ->execute()
      ->fetchAll();
  }

  /**
   * A broken binding fails the open as a refusal with one logged record.
   *
   * The proof runs through PHP's own fopen(): the wrapper answers the
   * protocol, so the caller sees the same FALSE it would see from a
   * permission error on a real file — and the request survives to do
   * more work afterwards.
   */
  public function testBrokenBindingFailsOpenWithLoggedRefusal(): void {
    $this->rebuildWithFlysystemBindings([
      'public' => ['driver' => 'nosuchplugin'],
    ]);
    self::assertSame(FlysystemPublicStream::class, $this->container->get('stream_wrapper_manager')->getClass('public'));

    $handle = @fopen('public://x.txt', 'r');
    self::assertFalse($handle, 'A broken binding must fail the open, not crash it.');

    $records = $this->channelRecords();
    self::assertCount(1, $records, 'The refusal leaves exactly one record, not a crash and not silence.');
    self::assertStringContainsString('nosuchplugin', $records[0]->message, 'The record names the defect the site builder can act on.');
    self::assertSame(3, (int) $records[0]->severity, 'A binding error is an error, not a warning.');

    $written = @file_put_contents('temporary://graceful-check.txt', 'alive');
    self::assertNotFalse($written, 'The request must remain healthy enough for its next file operation.');
    self::assertSame('alive', @file_get_contents('temporary://graceful-check.txt'));
  }

  /**
   * The log record and the verification finding say the same thing.
   *
   * A site builder reading the status report and an operator reading the
   * log must see one problem, described once — both surfaces quote the
   * same message the binding layer raised.
   */
  public function testVerificationAndLogCarryTheSameFinding(): void {
    $this->rebuildWithFlysystemBindings([
      'public' => ['driver' => 'nosuchplugin'],
    ]);

    @fopen('public://x.txt', 'r');

    $verifier = $this->container->get('flysystem.binding_verifier');
    $findings = $verifier->verifyBinding('public');
    self::assertCount(1, $findings, 'The same configuration answers with exactly one finding.');
    self::assertSame('error', $findings[0]['severity']);

    $messages = array_column($this->channelRecords(), 'message');
    self::assertNotEmpty($messages, 'Both the failed operation and the verification reach the log.');
    foreach ($messages as $message) {
      self::assertSame($findings[0]['message'], $message, 'Log and verification must quote the one raised error verbatim.');
    }
  }

  /**
   * A broken scheme does not disable the schemes that are sound.
   *
   * Binding errors are scheme-local: unbound schemes keep serving core's
   * own behavior, and the site still reads and writes files on every
   * path that was not misconfigured.
   */
  public function testBrokenSchemeLeavesOtherSchemesServing(): void {
    $this->rebuildWithFlysystemBindings([
      'public' => ['driver' => 'nosuchplugin'],
    ]);

    self::assertFalse(@fopen('public://x.txt', 'r'), 'The broken scheme refuses.');
    $uri = 'temporary://scheme-local-check.txt';
    self::assertNotFalse(@file_put_contents($uri, 'core-native'), 'Neighboring schemes still serve normally.');
    self::assertSame('core-native', @file_get_contents($uri));
  }

}
