<?php

declare(strict_types=1);

namespace Drupal\flysystem\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines the Flysystem adapter plugin attribute.
 *
 * An adapter is one class in one module: annotate it, implement
 * FlysystemAdapterInterface, and the manager discovers it. Enabling or
 * disabling the providing module is the only availability switch — the
 * base module never enumerates or classifies backends.
 *
 * @see \Drupal\flysystem\Plugin\FlysystemAdapterInterface
 * @see \Drupal\flysystem\Plugin\FlysystemAdapterManager
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class FlysystemAdapter extends Plugin {

  /**
   * Constructs a new attribute instance.
   *
   * @param string $id
   *   The adapter plugin ID.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $label
   *   (optional) The human-readable name of the adapter.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $description
   *   (optional) The adapter description.
   * @param string|null $deriver
   *   (optional) The deriver class.
   */
  public function __construct(
    public readonly string $id,
    public readonly ?TranslatableMarkup $label = NULL,
    public readonly ?TranslatableMarkup $description = NULL,
    public readonly ?string $deriver = NULL,
  ) {
  }

}
