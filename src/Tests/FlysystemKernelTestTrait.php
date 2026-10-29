<?php

declare(strict_types=1);

namespace Drupal\flysystem\Tests;

/**
 * Container-reboot mechanics for kernel tests that change flysystem settings.
 *
 * PHP instantiates stream wrappers without dependency injection and wrappers
 * reach services through the global container, so after a settings change the
 * container is rebuilt, installed as the global, and wrapper registration is
 * replayed — the same sequence a fresh request would run.
 */
trait FlysystemKernelTestTrait {

  /**
   * Rebuilds the test container on the given flysystem settings layer.
   *
   * The rebuild re-runs service providers, so wrapper definitions for bound
   * schemes are re-pointed at build time exactly as on a real site; the
   * explicit wrapper registration mirrors the request step that registers
   * wrappers from the final class map.
   *
   * @param array $bindings
   *   The value for the flysystem settings key.
   */
  protected function rebuildWithFlysystemBindings(array $bindings): void {
    $this->setSetting('flysystem', $bindings);
    $kernel = $this->container->get('kernel');
    $this->container = $kernel->rebuildContainer();
    \Drupal::setContainer($this->container);
    $this->container->get('stream_wrapper_manager')->register();
  }

}
