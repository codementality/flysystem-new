<?php

declare(strict_types=1);

namespace Drupal\flysystem\Adapter;

/**
 * The plugin-side seam: a backend describes itself through capabilities.
 *
 * The M1-02 adapter plugin interface will extend this; the M1-09 (local)
 * and M1-10 (memory) plugin classes must satisfy it. Consumers — the
 * factory, resolver, validation, and later wrappers — read the capability
 * contract only, never concrete plugin classes (evaluation §3.1 role 1:
 * "wrappers and UI read only the capability contract").
 */
interface CapabilitiesProviderInterface {

  /**
   * Returns the honest capability profile of this backend.
   */
  public function capabilities(): AdapterCapabilitiesInterface;

}
