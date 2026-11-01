<?php

declare(strict_types=1);

namespace Drupal\flysystem\Compiler;

use Drupal\flysystem\Exception\FlysystemAdapterTagException;
use Drupal\flysystem\Validation\SettingsValidator;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Collects tagged adapter services into the adapter manager's arguments.
 *
 * Every service tagged 'flysystem.adapter' declares one driver:
 * <code>{ name: flysystem.adapter, driver: <machine name> }</code>.
 * The pass reads them at container build — the same mechanism core uses
 * to feed its own tagged-service registries — and hands the manager a
 * sorted, driver-keyed definition list. Contributed adapters arrive
 * through the identical tag; nothing in this pass knows any backend by
 * name. Malformed tags fail the build with typed errors naming the
 * offending service, because a misread tag at file time would surface
 * as a mysterious missing storage.
 */
class RegisterFlysystemAdaptersPass implements CompilerPassInterface {

  /**
   * The service id whose argument 0 receives the definitions.
   */
  public const MANAGER_SERVICE = 'flysystem.adapter_manager';

  /**
   * {@inheritdoc}
   */
  public function process(ContainerBuilder $container): void {
    if (!$container->hasDefinition(self::MANAGER_SERVICE)) {
      return;
    }

    $definitions = [];
    foreach ($container->findTaggedServiceIds('flysystem.adapter', TRUE) as $service_id => $tags) {
      $class = $container->getDefinition($service_id)->getClass();
      foreach ($tags as $attributes) {
        $driver = $attributes['driver'] ?? NULL;
        if (!is_string($driver) || $driver === '') {
          throw new FlysystemAdapterTagException(
            $service_id,
            "the flysystem.adapter tag needs a non-empty 'driver' attribute."
          );
        }
        if (!preg_match(SettingsValidator::ADAPTER_ID_PATTERN, $driver)) {
          $canonical = strtolower(preg_replace('/[^a-z0-9_]/', '_', $driver) ?? $driver);
          throw new FlysystemAdapterTagException(
            $service_id,
            sprintf("driver '%s' is not a valid adapter id (lowercase letters, digits, and underscores only); the canonical spelling is '%s'.", $driver, $canonical)
          );
        }
        if (isset($definitions[$driver])) {
          throw new FlysystemAdapterTagException(
            $definitions[$driver]['service'],
            sprintf("driver '%s' is claimed by two services (also '%s').", $driver, $service_id)
          );
        }
        $definitions[$driver] = [
          'driver' => $driver,
          'label' => (string) ($attributes['label'] ?? $driver),
          'service' => $service_id,
          'class' => $class,
        ];
      }
    }

    ksort($definitions);
    $container->getDefinition(self::MANAGER_SERVICE)->setArgument(0, $definitions);
  }

}
