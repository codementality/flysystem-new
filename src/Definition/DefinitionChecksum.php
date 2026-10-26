<?php

declare(strict_types=1);

namespace Drupal\flysystem\Definition;

/**
 * Computes the stable identity key of a merged adapter definition.
 *
 * The operator factory memoizes built filesystems under this key, so the
 * checksum must be stable in exactly the ways assembly is not:
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
final class DefinitionChecksum {

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
   */
  public static function calculate(array $definition): string {
    $normalized = self::normalize($definition);
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
  private static function normalize(mixed $value): mixed {
    if (!is_array($value)) {
      return $value;
    }
    if (array_is_list($value)) {
      // Lists keep order; removed empties must not shift the keys the
      // encoder sees.
      $normalized = [];
      foreach ($value as $item) {
        $item = self::normalize($item);
        if ($item !== []) {
          $normalized[] = $item;
        }
      }
      return $normalized;
    }
    $normalized = [];
    foreach ($value as $key => $item) {
      $item = self::normalize($item);
      if ($item !== []) {
        // An empty section is the same definition as an absent one.
        $normalized[$key] = $item;
      }
    }
    ksort($normalized);
    return $normalized;
  }

}
