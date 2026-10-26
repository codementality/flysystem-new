<?php

declare(strict_types=1);

namespace Drupal\flysystem\Definition;

use Drupal\Core\Site\Settings;

/**
 * Reads the flysystem layer from settings.php ($settings['flysystem']).
 *
 * Uses the static Settings facade because this data is needed at build
 * time, before the container exists — the same pre-container pattern core
 * itself uses for wrapper configuration (PublicStream::basePath(),
 * evaluation §1.1). A missing or mistyped section reads as empty here;
 * precise structural complaints belong to the settings schema validator
 * (M1-05), which reads the raw layer, not this source.
 */
final class SettingsSource implements AdapterDefinitionSourceInterface {

  /**
   * {@inheritdoc}
   */
  public function getAdapterDefinitions(): array {
    return $this->section('adapters');
  }

  /**
   * {@inheritdoc}
   */
  public function getSchemeBindings(): array {
    return $this->section('bindings');
  }

  /**
   * Reads one section from the flysystem layer, defensively typed.
   *
   * @param string $name
   *   Section key ('adapters' or 'bindings').
   *
   * @return array<string, mixed>
   *   The section, or an empty array when the layer or section is absent
   *   or not an array.
   */
  private function section(string $name): array {
    $layer = Settings::get('flysystem', []);
    if (!is_array($layer)) {
      return [];
    }
    return is_array($layer[$name] ?? NULL) ? $layer[$name] : [];
  }

}
