<?php

declare(strict_types=1);

namespace Drupal\flysystem\Definition;

use Drupal\Core\Site\Settings;
use Drupal\flysystem\Exception\InvalidSettingsStructureException;

/**
 * Reads the flysystem layer from settings.php ($settings['flysystem']).
 *
 * Uses the static Settings facade because this data is needed at build
 * time, before the container exists — the same pre-container pattern core
 * itself uses for wrapper configuration (for example
 * \Drupal\Core\StreamWrapper\PublicStream::basePath()). A missing or
 * mistyped section reads as empty here; precise structural complaints
 * belong to the settings schema validator, which reads the raw layer,
 * not this source.
 */
final class SettingsSource implements AdapterDefinitionSourceInterface {

  /**
   * {@inheritdoc}
   */
  public function getAdapterDefinitions(): array {
    return $this->layers()['adapters'] ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function getSchemeBindings(): array {
    return $this->layers()['bindings'] ?? [];
  }

  /**
   * Reads the flysystem layer and presents it as two internal layers.
   *
   * @return array<string, array>
   *   Sections 'adapters' and 'bindings'; empty when the layer is empty.
   *
   * @throws \Drupal\flysystem\Exception\InvalidSettingsStructureException
   *   When the layer uses the two-section 'adapters'/'bindings' write
   *   form. That form was considered and dropped: the settings format is
   *   exactly one entry per scheme, and a rejected write is better than a
   *   silently misread one.
   */
  private function layers(): array {
    $layer = Settings::get('flysystem', []);
    if (!is_array($layer)) {
      return [];
    }
    if (isset($layer['adapters']) || isset($layer['bindings'])) {
      throw new InvalidSettingsStructureException('flysystem', "one entry per scheme, each carrying a string 'driver' (the two-section 'adapters'/'bindings' write form is not supported; the internal two-layer model is an implementation detail, not a configuration format)");
    }
    return self::normalizeFlat($layer);
  }

  /**
   * Presents the flat form through the same two internal layers.
   *
   * Scheme entries become adapter definitions ('driver' becomes 'plugin',
   * 'config' passes through untouched — keys the module does not
   * interpret stay inert) and scheme bindings to a deterministic
   * sanitized adapter id. Collisions after sanitization gain numeric
   * suffixes in sorted scheme order, so a configuration never silently
   * loses a scheme. Binding options on the scheme entry ('disabled',
   * 'publicly_accessible') are copied when boolean; a boolean
   * config['public'] also answers the publicly-accessible question unless
   * the binding-level sibling states it explicitly. Any other value is
   * left inside the adapter config as inert data.
   *
   * @param array $layer
   *   The flat-form layer.
   *
   * @return array<string, array>
   *   Normalized 'adapters' and 'bindings' sections.
   */
  private static function normalizeFlat(array $layer): array {
    $adapters = [];
    $bindings = [];
    $schemes = array_keys($layer);
    sort($schemes);
    foreach ($schemes as $scheme) {
      $entry = $layer[$scheme];
      if (!is_array($entry) || !isset($entry['driver']) || !is_string($entry['driver'])) {
        continue;
      }
      $config = is_array($entry['config'] ?? NULL) ? $entry['config'] : [];
      // Derive the ADAPTER ID, never a scheme name. Adapter ids allow
      // underscores (settings validation accepts ^[a-z0-9_]+$); scheme
      // names are carried through verbatim as binding keys below, and
      // the settings validator — not this code — is the sole authority
      // on which scheme names are legal (it rejects underscores, which
      // PHP's stream wrapper registration cannot register at all).
      $base = preg_replace('/[^a-z0-9_]/', '_', strtolower((string) $scheme)) ?? '';
      $id = $base === '' ? 'adapter' : $base;
      $suffix = 2;
      while (isset($adapters[$id])) {
        $id = $base . '_' . $suffix;
        $suffix++;
      }
      $adapters[$id] = [
        'plugin' => $entry['driver'],
        'config' => $config,
      ];
      $binding = ['adapter' => $id];
      if (isset($entry['disabled']) && is_bool($entry['disabled'])) {
        $binding['disabled'] = $entry['disabled'];
      }
      if (isset($entry['publicly_accessible']) && is_bool($entry['publicly_accessible'])) {
        $binding['publicly_accessible'] = $entry['publicly_accessible'];
      }
      elseif (isset($config['public']) && is_bool($config['public'])) {
        $binding['publicly_accessible'] = $config['public'];
      }
      $bindings[(string) $scheme] = $binding;
    }
    return ['adapters' => $adapters, 'bindings' => $bindings];
  }

}
