<?php

declare(strict_types=1);

namespace Drupal\flysystem_memory\Plugin\FlysystemAdapter;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\flysystem\Adapter\FlysystemAdapterBase;
use Drupal\flysystem\Attribute\FlysystemAdapter;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;

/**
 * The in-memory adapter plugin — a test harness, never a backend.
 *
 * Its sole purpose is adapter-agnosticism: factory behavior (identity,
 * memoization, posture, error paths) must be provable against more than
 * one backend through the same interfaces, so nothing accidentally
 * tests "local" where it means "any adapter". It stores whole files in
 * RAM, ignores any configured root, and touches no filesystem at all.
 *
 * Policy, enforced by structure: this plugin ships in a hidden
 * testing-package submodule that sites never enable and admin
 * interfaces never list (the main module requires it only as a test
 * dependency); adapter discovery only ever sees enabled modules, so the
 * adapter's existence is exactly the module's presence. The availability
 * method reports the truth about the underlying library package, which
 * is a development-only dependency: without it, the plugin says so and
 * the factory fails with its typed, adapter-naming package error
 * instead of this class touching a missing dependency.
 */
#[FlysystemAdapter(
  id: 'in_memory',
  label: new TranslatableMarkup('In-memory (testing only)'),
)]
final class InMemory extends FlysystemAdapterBase {

  /**
   * {@inheritdoc}
   */
  public function adapter(): FilesystemAdapter {
    // One object per plugin instance: the in-memory store is the
    // instance, so schemes bound to in_memory each keep their own.
    $this->store ??= new InMemoryFilesystemAdapter();
    return $this->store;
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return class_exists(InMemoryFilesystemAdapter::class);
  }

  /**
   * The league adapter object, built once for this plugin instance.
   *
   * @var \League\Flysystem\FilesystemAdapter|null
   */
  protected ?FilesystemAdapter $store = NULL;

}
