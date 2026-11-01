<?php

declare(strict_types=1);

namespace Drupal\flysystem\Adapter;

use League\Flysystem\FilesystemAdapter;

/**
 * The adapter service contract: backends describe themselves by methods.
 *
 * An adapter is a plain container service: any module registers one by
 * implementing this interface and declaring the service tagged
 * 'flysystem.adapter' with a driver name — four lines of YAML, the same
 * extension pattern core uses for its own tagged services. Container
 * construction means dependencies arrive injected; the manager never
 * builds anything itself.
 *
 * The backend's one job is producing the league adapter object for a
 * given configuration array; wrapping it in a Filesystem operator (and
 * the operation defaults that go with it) is the factory's assembly, so
 * every backend shares one posture path. Feature questions join this
 * contract only where a real consumer needs them; methods for URL
 * generation and self-validation arrive with the work that calls them.
 */
interface FlysystemAdapterInterface {

  /**
   * Builds the league adapter object for this backend and configuration.
   *
   * @param array $config
   *   Adapter configuration values, already merged and resolved by the
   *   caller (definition config plus binding overrides). The backend
   *   owns the meaning of every key it recognizes and ignores the rest.
   *
   * @return \League\Flysystem\FilesystemAdapter
   *   The league adapter; callers wrap it in an operator and never use
   *   it raw, because traversal protection is enforced at the operator
   *   layer.
   */
  public function adapter(array $config): FilesystemAdapter;

  /**
   * Whether this backend can work right now.
   *
   * The service's own answer to "is the required package installed, is
   * the configuration usable". A FALSE result must be reportable as a
   * structured finding, never a fatal.
   */
  public function isAvailable(): bool;

}
