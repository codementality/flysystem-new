<?php

declare(strict_types=1);

namespace Drupal\flysystem\Tests;

/**
 * Reboot mechanics for browser tests that rewrite settings.php bindings.
 *
 * Browser requests pick settings up per-request; the test process needs the
 * same fresh read so its own wrapper writes land in the bound roots. Test
 * classes write the settings.php entries themselves (the flysystem key and
 * any companion keys a leg needs), then call this to install the state.
 */
trait FlysystemFunctionalTestTrait {

  /**
   * Installs the freshly written settings into this process.
   */
  protected function rebootWithFlysystemSettings(): void {
    $kernel = $this->container->get('kernel');
    $this->container = $kernel->rebuildContainer();
    \Drupal::setContainer($this->container);
    $this->container->get('stream_wrapper_manager')->register();
  }

}
