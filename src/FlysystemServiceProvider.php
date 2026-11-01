<?php

declare(strict_types=1);

namespace Drupal\flysystem;

use Drupal\flysystem\Compiler\RegisterFlysystemAdaptersPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * Adds the adapter-tag collection pass to the container build.
 *
 * Registering compiler passes from a service provider is core's own
 * mechanism for module build-phase contributions (core's
 * SerializationServiceProvider uses it the same way), so no file in
 * this module is consulted by the kernel itself.
 */
class FlysystemServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    $container->addCompilerPass(new RegisterFlysystemAdaptersPass());
  }

}
