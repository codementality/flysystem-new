<?php

declare(strict_types=1);

namespace Drupal\flysystem\Plugin;

use Drupal\Component\Plugin\ConfigurableInterface;
use League\Flysystem\FilesystemOperator;

/**
 * The adapter plugin contract: backends describe themselves by methods.
 *
 * Modeled on Search API's backend plugins (owner ruling 2026-10-26): the
 * base module does not define capability types about adapters — each
 * adapter plugin answers for itself, at the moment a caller asks.
 * Feature questions join this interface only when a real consumer needs
 * them (first candidate: the webroot-containment question the settings
 * validator asks, wired through issue #2's manager); URL generation and
 * self-validation methods arrive with the wrapper and verification work.
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
