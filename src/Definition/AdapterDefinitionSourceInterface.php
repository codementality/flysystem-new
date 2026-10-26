<?php

declare(strict_types=1);

namespace Drupal\flysystem\Definition;

/**
 * A source of adapter definitions and scheme bindings, as plain arrays.
 *
 * The source layer is deliberately dumb: it returns its two layers and
 * nothing else. No validation (that is the settings schema validator's
 * job, M1-05), no merging or precedence (the resolver's, M1-06), no
 * normalization. D4/D8 require that no source-specific branch exists
 * anywhere below the resolver, which is why this interface carries no
 * origin/type sniffing method — consumers cannot even ask which source
 * they are holding.
 *
 * Canonical layer shape (as produced by settings.php, and by the config
 * entity source in M3):
 * - adapters: array keyed by adapter id, each with keys 'plugin' (string)
 *   and optional 'config' (array).
 * - bindings: array keyed by scheme, each with key 'adapter' (string) and
 *   optional keys 'config' (array), 'disabled' (bool), and
 *   'publicly_accessible' (bool; default TRUE for core 'public', FALSE
 *   elsewhere — the defaulting is validation-side, see M1-05).
 */
interface AdapterDefinitionSourceInterface {

  /**
   * Returns adapter definitions keyed by adapter id.
   *
   * @return array<string, mixed>
   *   The raw adapter-definition layer; empty when this source has none.
   */
  public function getAdapterDefinitions(): array;

  /**
   * Returns scheme bindings keyed by scheme name.
   *
   * @return array<string, mixed>
   *   The raw binding layer; empty when this source has none.
   */
  public function getSchemeBindings(): array;

}
