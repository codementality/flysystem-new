<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Kernel\Core;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Site\Settings;

/**
 * Replayed from core's ReadOnlyStreamWrapperTest, scheme-parameterized.
 *
 * Core's corpus registers a second, read-only scheme whose wrapper
 * refuses write operations while reads work; this replay adopts that
 * parameterization pattern. The harness scheme stays registered, and
 * a second scheme (fsreadonly) is registered here with a test-scope
 * wrapper that mirrors core's read-only refusal shape over the subject
 * wrapper class. From the harness point of view the suite then runs on
 * the read-only scheme: its URI builder, dispatch probe, and real-path
 * helper all follow the registered scheme under test.
 *
 * The write-rejection legs should hold against the read-only posture
 * even once the wrapper is implemented, and the read legs (open for
 * reading, shared lock, close) must reach the subject wrapper — today
 * they go red at the stub's refusal. The seed for the readable file
 * is written directly onto the real directory the read-only wrapper
 * mirrors — a disk-bound surface — so the legs themselves live at the
 * local tier (ReadOnlyLocalStorageReplay); only the local host
 * inherits them. No by-name skips anywhere in this replay.
 */
abstract class ReadOnlyReplay extends FileReplayBase {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('stream_wrapper.fsreadonly', TestReadOnlyStreamWrapper::class)
      ->addTag('stream_wrapper', ['scheme' => 'fsreadonly']);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Bind the read-only scheme to the same storage the harness just
    // configured for the replay scheme: a registered wrapper without
    // a binding resolves core-native and would answer nothing to
    // operate on. (Harness placement of the binding, not a new
    // expectation: the replayed legs are unchanged.)
    $bindings = Settings::get('flysystem', []);
    $bindings['fsreadonly'] = [
      'adapter' => $this->replayAdapter(),
      'config' => $this->replayConfig(),
    ];
    $this->setSetting('flysystem', $bindings);
    // The replayed legs run against the read-only scheme; the harness
    // helpers follow this property. The container was already built
    // with both schemes registered when the parent set-up ran.
    $this->scheme = 'fsreadonly';
  }

}
