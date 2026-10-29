<?php

declare(strict_types=1);

namespace Drupal\flysystem\Tests\Kernel\StreamWrapper;

use Drupal\flysystem\Tests\FlysystemBootTestTrait;
use Drupal\flysystem\Tests\FlysystemKernelTestTrait;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that a broken binding refuses metadata and directory operations.
 *
 * The graceful posture covers every wrapper entry point, not just opens:
 * PHP's stat family, directory iteration, and the mutation functions must
 * all answer their documented failure values against a misconfigured
 * scheme, leave actionable records in the site's log, and keep the
 * request healthy. Records are read from the watchdog table so the proof
 * covers the pipeline an operator actually sees.
 */
#[Group('flysystem')]
#[RunTestsInSeparateProcesses]
final class BrokenBindingMetadataRefusalTest extends KernelTestBase {

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
   * @return string[]
   *   The watchdog messages, oldest first.
   */
  private function channelMessages(): array {
    $rows = $this->container->get('database')
      ->select('watchdog', 'w')
      ->fields('w', ['message'])
      ->condition('w.type', 'flysystem')
      ->orderBy('w.wid')
      ->execute()
      ->fetchAll();
    return array_map(static fn(object $row): string => (string) $row->message, $rows);
  }

  /**
   * Every stat, directory, and mutation answer refuses without a crash.
   */
  public function testAllFileFunctionsRefuseTheBrokenBinding(): void {
    $this->rebuildWithFlysystemBindings([
      'public' => ['driver' => 'nosuchplugin'],
    ]);

    $refusals = [
      'file_exists' => static fn(): bool => !@file_exists('public://a.txt'),
      'is_file' => static fn(): bool => !@is_file('public://a.txt'),
      'is_dir' => static fn(): bool => !@is_dir('public://d'),
      'stat' => static fn(): bool => @stat('public://a.txt') === FALSE,
      'scandir' => static fn(): bool => @scandir('public://d') === FALSE,
      'mkdir' => static fn(): bool => !@mkdir('public://d', 0755, TRUE),
      'unlink' => static fn(): bool => !@unlink('public://a.txt'),
      'rename' => static fn(): bool => !@rename('public://a.txt', 'public://b.txt'),
      'touch' => static fn(): bool => !@touch('public://a.txt'),
    ];
    foreach ($refusals as $name => $check) {
      self::assertTrue($check(), "PHP's {$name}() must report failure on a broken binding, never crash.");
    }

    $messages = $this->channelMessages();
    self::assertNotEmpty($messages, 'Refused attempts leave actionable records, never silence.');
    self::assertCount(1, array_unique($messages), 'Every surface quotes the one raised binding error, not a different message per method.');
    self::assertStringContainsString('nosuchplugin', $messages[0]);

    $written = @file_put_contents('temporary://metadata-graceful.txt', 'alive');
    self::assertNotFalse($written, 'The request stays healthy after the refusals.');
  }

}
