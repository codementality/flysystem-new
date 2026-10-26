<?php

declare(strict_types=1);

namespace Drupal\flysystem\Plugin;

use Drupal\Component\Plugin\ConfigurableInterface;
use League\Flysystem\FilesystemOperator;

/**
 * The adapter plugin contract: backends describe themselves by methods.
 *
 * Modeled on the Search API module's backend plugins: the base module
 * defines no capability types about adapters — each adapter plugin
 * answers for itself, at the moment a caller asks. Feature questions
 * join this interface only when a real consumer needs them; methods for
 * URL generation and self-validation arrive with the work that actually
 * calls them.
 */
interface FlysystemAdapterInterface extends ConfigurableInterface {

  /**
   * Builds a configured filesystem operator for this backend.
   *
   * @param array $config
   *   Adapter configuration values, already merged and resolved by the
   *   caller (definition config + binding overrides). The plugin owns the
   *   meaning of every key.
   *
   * @return \League\Flysystem\FilesystemOperator
   *   A working operator; callers use it through the league interface and
   *   never inspect the adapter behind it.
   */
  public function buildFilesystem(array $config = []): FilesystemOperator;

  /**
   * Whether this backend can work right now.
   *
   * The plugin's own answer to "is the required package installed, is the
   * configuration usable" — mirrors Search API's isAvailable(). A FALSE
   * result must be reportable as a structured finding, never a fatal.
   */
  public function isAvailable(): bool;

}
