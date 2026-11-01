<?php

declare(strict_types=1);

namespace Drupal\flysystem_adapter_test\Adapter;

use Drupal\flysystem\Adapter\FlysystemAdapterInterface;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;

/**
 * A contributed-style adapter: nothing about it is known to the module.
 *
 * This class exists to prove the extension rail: any module can ship an
 * adapter by implementing the contract and declaring four lines of
 * tagged service YAML. The module's manager, resolver, and factory
 * consume it with zero changes to production code, the same path third
 * parties will follow. It stores whole files in RAM and touches no
 * filesystem; it lives in a hidden testing-package module so sites
 * never enable it.
 */
final class MemoryBackedTestAdapter implements FlysystemAdapterInterface {

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
