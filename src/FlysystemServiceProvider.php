<?php

declare(strict_types=1);

namespace Drupal\flysystem;

use Drupal\Core\DependencyInjection\ServiceProviderInterface;
use Drupal\Core\Site\Settings;
use Drupal\flysystem\StreamWrapper\FlysystemPrivateStream;
use Drupal\flysystem\StreamWrapper\FlysystemPublicStream;
use Drupal\flysystem\StreamWrapper\FlysystemTemporaryStream;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Registers Flysystem-backed wrappers for bound core storage schemes.
 *
 * At container build time the only definition data readable is the
 * settings.php layer, so this provider reads the flysystem settings and,
 * for each core storage scheme it finds bound and not disabled, re-points
 * that scheme's tagged stream wrapper definition at this module's wrapper
 * class. Re-pointing happens before compilation, so the class map that
 * lets the wrapper manager register wrappers without instantiating them
 * and the service the manager dispatches through are repointed together.
 *
 * Registration is not configuration: nothing here resolves a binding,
 * validates a plugin, or touches a filesystem. A scheme whose settings
 * entry cannot be used at all is left on core's own wrapper, silently —
 * the configuration error surfaces only when something actually reaches
 * that scheme through the storage factory. That keeps a malformed binding
 * from breaking container build, which runs on every request, for people
 * who never touch the broken scheme.
 */
final class FlysystemServiceProvider implements ServiceProviderInterface {

  /**
   * The core storage schemes this module may replace.
   *
   * Registering brand-new schemes is an administrative concern arriving
   * later; this class only owns replacement of core's storage wrappers.
   *
   * @var array<string, class-string>
   */
  private const SCHEME_WRAPPERS = [
    'public' => FlysystemPublicStream::class,
    'private' => FlysystemPrivateStream::class,
    'temporary' => FlysystemTemporaryStream::class,
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    $bindings = $this->boundSchemes();
    foreach (self::SCHEME_WRAPPERS as $scheme => $class) {
      if (!isset($bindings[$scheme])) {
        // Unbound or unreadable: leave core's definition untouched, and
        // for private (which core may not have defined at all) add none.
        continue;
      }
      $definition = new Definition($class);
      // Public by necessity: the wrapper manager's service locator is built
      // from tags at compile time, and a replacement definition that nothing
      // else references by id is dropped as unused when the container is
      // compiled for production (test-mode containers keep every service,
      // which is why this must be held here rather than discovered there).
      $definition->setPublic(TRUE);
      $definition->addTag('stream_wrapper', ['scheme' => $scheme]);
      // Re-point an existing definition, or introduce one for a scheme
      // core never registered (for example private with no private file
      // path set). Either way the scheme id and its tag are preserved.
      $container->setDefinition("stream_wrapper.$scheme", $definition);
    }
  }

  /**
   * Reads which core storage schemes are bound, from settings alone.
   *
   * A scheme counts as bound when its entry carries a non-empty string
   * driver and is not disabled. The driver value is never validated here:
   * whether the named plugin exists is a resolution question deferred to
   * first use. Anything unreadable at this shape is skipped so the scheme
   * stays core-native.
   *
   * @return array<string, bool>
   *   Keys of bound schemes, mapped to TRUE.
   */
  private function boundSchemes(): array {
    $layer = Settings::get('flysystem', []);
    if (!is_array($layer)) {
      return [];
    }
    $bound = [];
    foreach (array_keys(self::SCHEME_WRAPPERS) as $scheme) {
      $entry = $layer[$scheme] ?? NULL;
      if (!is_array($entry)) {
        continue;
      }
      if (($entry['disabled'] ?? FALSE) === TRUE) {
        continue;
      }
      $driver = $entry['driver'] ?? NULL;
      if (is_string($driver) && $driver !== '') {
        $bound[$scheme] = TRUE;
      }
    }
    return $bound;
  }

}
