<?php

declare(strict_types=1);

namespace Drupal\flysystem\Plugin\FlysystemAdapter;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\flysystem\Attribute\FlysystemAdapter;
use Drupal\flysystem\Plugin\FlysystemAdapterBase;
use Drupal\flysystem\Plugin\FlysystemAdapterInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;

/**
 * The in-memory adapter plugin — a test harness, never a backend.
 *
 * Its sole purpose is adapter-agnosticism: factory behavior (identity,
 * memoization, posture, error paths) must be provable against more than
 * one backend through the same interfaces, so nothing accidentally tests
 * "local" where it means "any adapter". It stores whole files in RAM,
 * ignores any configured root, and touches no filesystem at all.
 *
 * Policy, deliberately structural: this plugin is never user-selectable
 * and never a production backend. Administration interfaces must omit it;
 * verification tooling reports bindings that reference it as
 * non-production findings. Its availability method reports the truth —
 * the underlying package is a development-only dependency, so on an
 * installation without it the plugin reports unavailable and the factory
 * fails with the typed, plugin-naming package error rather than this
 * class erroring on a missing dependency.
 */
#[FlysystemAdapter(id: 'memory', label: new TranslatableMarkup('In-memory (testing only)'))]
final class Memory extends FlysystemAdapterBase implements FlysystemAdapterInterface {

  /**
   * {@inheritdoc}
   */
  public function buildFilesystem(array $config = []): FilesystemOperator {
    $config += $this->getConfiguration();
    return new Filesystem(
      new InMemoryFilesystemAdapter(),
      [
        'allow_relative_path_traversal' => (bool) ($config['allow_relative_path_traversal'] ?? TRUE),
      ],
    );
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return class_exists(InMemoryFilesystemAdapter::class);
  }

}
