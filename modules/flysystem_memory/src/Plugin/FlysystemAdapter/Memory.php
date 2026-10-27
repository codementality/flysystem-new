<?php

declare(strict_types=1);

namespace Drupal\flysystem_memory\Plugin\FlysystemAdapter;

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
 * Policy, enforced by structure: this plugin lives in a hidden
 * testing-package submodule that sites never enable and admin interfaces
 * never list (the main module requires it only as a test dependency).
 * Verification tooling reports any binding that references it as a
 * non-production finding. The availability method reports the truth about
 * the underlying library package, which is a development-only
 * dependency: without it, the plugin says so and the factory fails with
 * its typed, plugin-naming package error instead of this class touching
 * a missing dependency.
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
