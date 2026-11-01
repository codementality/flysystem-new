<?php

declare(strict_types=1);

namespace Drupal\flysystem\Adapter;

use Drupal\Component\Plugin\PluginInspectionInterface;
use League\Flysystem\FilesystemAdapter;

/**
 * The adapter plugin contract: backends describe themselves by methods.
 *
 * An adapter is a plugin: a class in the module's Plugin/FlysystemAdapter
 * directory carrying the adapter attribute, discovered by the plugin
 * API, cached, alterable through the info hook, and instantiated by the
 * plugin factory with the binding's configuration already in its
 * constructor. Nothing in this module builds adapters with new; the
 * manager does, exactly the way Search API builds its datasource and
 * backend plugins.
 *
 * The backend's one job is producing the league adapter object for the
 * configuration it was created with; wrapping it in a Filesystem
 * operator (and the operation defaults that go with it) is the
 * factory's assembly, so every backend shares one posture path.
 * Feature questions join this contract only where a real consumer
 * needs them; methods for URL generation and self-validation arrive
 * with the work that calls them.
 */
interface FlysystemAdapterInterface extends PluginInspectionInterface {

  /**
   * Returns the league adapter object for this plugin's configuration.
   *
   * The configuration arrives through the plugin constructor —
   * definition config plus binding overrides, merged and resolved by
   * the caller — so this method takes no arguments. Implementations
   * build the object once and return the same one on every call.
   *
   * @return \League\Flysystem\FilesystemAdapter
   *   The league adapter; callers wrap it in an operator and never use
   *   it raw, because traversal protection is enforced at the operator
   *   layer.
   */
  public function adapter(): FilesystemAdapter;

  /**
   * Whether this backend can work right now.
   *
   * The plugin's own answer to "is the required package installed, is
   * the configuration usable". A FALSE result must be reportable as a
   * structured finding, never a fatal.
   */
  public function isAvailable(): bool;

}
