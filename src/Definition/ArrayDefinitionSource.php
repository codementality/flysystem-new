<?php

declare(strict_types=1);

namespace Drupal\flysystem\Definition;

/**
 * An array-backed definition source: the same layer shape, in memory.
 *
 * This class replaces a definition *source* for tests and programmatic
 * use — it is deliberately not the memory *adapter* (D10 keeps that
 * distinction sharp: the memory adapter replaces a backend, this replaces
 * a source). It exists so the resolver can prove D4/D8 source-agnosticism
 * against a second implementation today, and so M3's config-entity source
 * has a shipped behavioral reference to match. Section handling is
 * identical to SettingsSource by design: any divergence here would be
 * source-specific behavior leaking below the resolver.
 */
final class ArrayDefinitionSource implements AdapterDefinitionSourceInterface {

  /**
   * Constructs the source over a flysystem-shaped layer.
   *
   * @param array<string, mixed> $layer
   *   The same shape as $settings['flysystem']: optional 'adapters' and
   *   'bindings' sections, each a keyed array.
   */
  public function __construct(
    private readonly array $layer,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getAdapterDefinitions(): array {
    return is_array($this->layer['adapters'] ?? NULL) ? $this->layer['adapters'] : [];
  }

  /**
   * {@inheritdoc}
   */
  public function getSchemeBindings(): array {
    return is_array($this->layer['bindings'] ?? NULL) ? $this->layer['bindings'] : [];
  }

}
