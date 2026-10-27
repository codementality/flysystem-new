<?php

declare(strict_types=1);

namespace Drupal\flysystem\Definition;

/**
 * Computes the stable identity key of a merged adapter definition.
 *
 * Composed by the classes that memoize or reason about operator identity
 * (the factory; later, the verification API) rather than exposed as a
 * global utility: the checksum is internal machinery. Adapter authors
 * implement the plugin interface and never compute keys, so nothing here
 * is public or static — the entry point is protected and reaches the
 * outside world only through a composing class.
 *
 * The key must be stable in exactly the ways assembly is not:
 *
 * - Named mapping keys carry no meaning of their own order; every mapping
 *   is sorted recursively before hashing, so the same definition merged
 *   from sources in any order keys identically.
 * - Lists are positional data and their order is preserved.
 * - Empty mappings and empty lists are dropped wherever they sit: a
 *   section written as an empty array is the same definition as a
 *   section left out.
 * - Values keep their types ('1' and 1 key apart), because the library
 *   receives them as written.
 *
 * Secrets key as references, never as resolved values: configuration
 * written as an environment reference ('env:NAME') is hashed as that
 * token and the environment is never read here. A secret's value may
 * rotate without forking operator identity, and the plaintext never
 * reaches a cache key, log line, or diagnostic.
 */
trait DefinitionChecksumTrait {

  /**
   * Returns the hex identity digest of a merged definition.
   *
   * @param array $definition
   *   A resolved definition array, conventionally with a 'plugin' key
   *   and an optional 'config' mapping.
   *
   * @return string
   *   A 64-character lowercase SHA-256 hex digest, stable across
   *   processes and releases for the same effective definition.
   *
   * @throws \InvalidArgumentException
   *   When the definition cannot be encoded — definitions hold plain
   *   scalar values, and a resource or object is a wiring bug worth a
   *   loud failure, not a silent key collision.
   */
  protected function definitionChecksum(array $definition): string {
    $normalized = $this->normalizeDefinition($definition);
    $encoded = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    if ($encoded === FALSE) {
      throw new \InvalidArgumentException('The definition could not be encoded for checksumming; definitions must hold plain scalar values.');
    }
    return hash('sha256', $encoded);
  }

  /**
   * Sorts mappings, preserves lists, and drops empty sections recursively.
   *
   * @param mixed $value
   *   Any value found in the definition.
   *
   * @return mixed
   *   The normalized value.
   */
  private function normalizeDefinition(mixed $value): mixed {
    if (!is_array($value)) {
      return $value;
    }
    if (array_is_list($value)) {
      // Lists keep order; removed empties must not shift the keys the
      // encoder sees.
      $normalized = [];
      foreach ($value as $item) {
        $item = $this->normalizeDefinition($item);
        if ($item !== []) {
          $normalized[] = $item;
        }
      }
      return $normalized;
    }
    $normalized = [];
    foreach ($value as $key => $item) {
      $item = $this->normalizeDefinition($item);
      if ($item !== []) {
        // An empty section is the same definition as an absent one.
        $normalized[$key] = $item;
      }
    }
    ksort($normalized);
    return $normalized;
  }

}
