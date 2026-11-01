<?php

declare(strict_types=1);

namespace Drupal\flysystem_adapter_test\Plugin\FlysystemAdapter;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\flysystem\Adapter\FlysystemAdapterBase;
use Drupal\flysystem\Attribute\FlysystemAdapter;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;

/**
 * A contributed-style adapter: nothing about it is known to the module.
 *
 * This class exists to prove the extension rail: any module ships an
 * adapter by placing a class in its Plugin/FlysystemAdapter directory
 * with the adapter attribute on it — the plugin API's own discovery,
 * the same pattern Search API uses for datasources and backends. The
 * module's manager, resolver, and factory consume it with zero changes
 * to production code, the same path third parties will follow. It
 * stores whole files in RAM and touches no filesystem; it lives in a
 * hidden testing-package module so sites never enable it.
 */
#[FlysystemAdapter(
  id: 'test_adapter',
  label: new TranslatableMarkup('Memory-backed test adapter'),
)]
final class MemoryBackedTestAdapter extends FlysystemAdapterBase {

  /**
   * {@inheritdoc}
   */
  public function adapter(): FilesystemAdapter {
    return new InMemoryFilesystemAdapter();
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return class_exists(InMemoryFilesystemAdapter::class);
  }

}
