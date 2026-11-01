<?php

declare(strict_types=1);

namespace Drupal\flysystem_memory\Adapter;

use Drupal\flysystem\Adapter\FlysystemAdapterInterface;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;

/**
 * The in-memory adapter service — a test harness, never a backend.
 *
 * Its sole purpose is adapter-agnosticism: factory behavior (identity,
 * memoization, posture, error paths) must be provable against more than
 * one backend through the same interfaces, so nothing accidentally
 * tests "local" where it means "any adapter". It stores whole files in
 * RAM, ignores any configured root, and touches no filesystem at all.
 *
 * Policy, enforced by structure: this service lives in a hidden
 * testing-package submodule that sites never enable and admin
 * interfaces never list (the main module requires it only as a test
 * dependency). The availability method reports the truth about the
 * underlying library package, which is a development-only dependency:
 * without it, the service says so and the factory fails with its typed,
 * adapter-naming package error instead of this class touching a missing
 * dependency.
 */
final class InMemory implements FlysystemAdapterInterface {

  /**
   * {@inheritdoc}
   */
  public function adapter(array $config): FilesystemAdapter {
    return new InMemoryFilesystemAdapter();
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return class_exists(InMemoryFilesystemAdapter::class);
  }

}
