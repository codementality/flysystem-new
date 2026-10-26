<?php

declare(strict_types=1);

namespace Drupal\flysystem\Definition;

use Drupal\Core\Site\Settings;

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
   * Reads the flysystem layer, normalizing the legacy shape if present.
   *
   * @return array<string, array>
   *   Sections 'adapters' and 'bindings'; empty when the layer or a
   *   section is absent or not an array.
   */
  private function layers(): array {
    $layer = Settings::get('flysystem', []);
    if (!is_array($layer)) {
      return [];
    }
    if (self::isLegacyLayer($layer)) {
      return self::normalizeLegacy($layer);
    }
    return [
      'adapters' => is_array($layer['adapters'] ?? NULL) ? $layer['adapters'] : [],
      'bindings' => is_array($layer['bindings'] ?? NULL) ? $layer['bindings'] : [],
    ];
  }

  /**
   * Detects the flysystem 2.x settings shape.
   *
   * The 2.x module (see its published README, CONFIGURATION section) keys
   * wrappers by scheme name directly under $settings['flysystem'], each
   * entry carrying a string 'driver' plus optional 'config'. A layer that
   * already carries the modern 'adapters'/'bindings' keys is never
   * reinterpreted.
   *
   * @param array $layer
   *   The raw flysystem layer.
   *
   * @return bool
   *   TRUE when the layer looks like the 2.x shape.
   */
  private static function isLegacyLayer(array $layer): bool {
    if (isset($layer['adapters']) || isset($layer['bindings'])) {
      return FALSE;
    }
    foreach ($layer as $entry) {
      if (is_array($entry) && isset($entry['driver']) && is_string($entry['driver'])) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Presents the 2.x shape through the modern two-layer interface.
   *
   * Scheme entries become adapter definitions ('driver' becomes 'plugin',
   * 'config' passes through untouched — including 2.x keys the modern
   * layers never interpret, which stay inert) and scheme bindings to a
   * deterministic sanitized adapter id. Collisions after sanitization
   * gain numeric suffixes in sorted scheme order, so a site's
   * configuration never silently loses a scheme. A boolean 'public'
   * config flag becomes the binding's 'publicly_accessible' answer; any
   * other value is left inside the adapter config as inert data.
   *
   * @param array $layer
   *   The legacy layer.
   *
   * @return array<string, array>
   *   Normalized 'adapters' and 'bindings' sections.
   */
  private static function normalizeLegacy(array $layer): array {
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
      if (isset($config['public']) && is_bool($config['public'])) {
        $binding['publicly_accessible'] = $config['public'];
      }
      $bindings[(string) $scheme] = $binding;
    }
    return ['adapters' => $adapters, 'bindings' => $bindings];
  }

}
