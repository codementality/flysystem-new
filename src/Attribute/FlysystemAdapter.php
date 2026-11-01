<?php

declare(strict_types=1);

namespace Drupal\flysystem\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Declares a Flysystem adapter plugin.
 *
 * This attribute is the entire extension surface for shipping a
 * backend: a class in a module's Plugin/FlysystemAdapter directory,
 * implementing the adapter contract and carrying this attribute with a
 * machine-name id, is discovered by the adapter manager, cached in the
 * discovery cache, alterable through the info hook, and instantiated
 * through the plugin factory — the same discovery model Search API
 * uses for its datasources and backends. The id doubles as the driver
 * name a site writes in its settings, so one word names a backend
 * everywhere: attribute, definition, configuration.
 *
 * @see \Drupal\flysystem\Adapter\FlysystemAdapterManager
 * @see \Drupal\flysystem\Adapter\FlysystemAdapterInterface
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class FlysystemAdapter extends Plugin {

  /**
   * Constructs the adapter definition.
   *
   * @param string $id
   *   The adapter machine name, written lowercase with underscores:
   *   the same string a site uses as its 'driver' value.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $label
   *   (optional) The human-readable name of the backend.
   * @param class-string|null $deriver
   *   (optional) A deriver class yielding multiple definitions from one
   *   class.
   */
  public function __construct(
    public readonly string $id,
    public readonly ?TranslatableMarkup $label = NULL,
    public readonly ?string $deriver = NULL,
  ) {
  }

}
