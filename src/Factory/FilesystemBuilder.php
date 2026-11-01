<?php

declare(strict_types=1);

namespace Drupal\flysystem\Factory;

use Drupal\Component\Utility\NestedArray;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\PathNormalizer;

/**
 * Composes library filesystem operators from adapter objects and config.
 *
 * This is the module's single assembly seam (the only place a league
 * Filesystem is constructed): an adapter object produced by an adapter
 * plugin plus the merged binding configuration go in, a configured
 * FilesystemOperator comes out. Traversal posture and operation
 * defaults land here exactly as the library expresses them — the
 * operator's own configuration keys and path normalizer, with no
 * invented policy and no knowledge of which backend produced the
 * adapter. Collaborators are replaceable through the constructor: a
 * site or test may inject its own path normalizer, and operation
 * defaults (merged under each build's configuration, per-leaf, in the
 * library's precedence order) arrive as injection, configured by the
 * container.
 *
 * The builder holds no state and memoizes nothing: identity caching
 * across a request is the operator factory's concern, so building the
 * same definition twice through here genuinely composes twice.
 */
final class FilesystemBuilder {

  /**
   * Constructs the builder.
   *
   * @param \League\Flysystem\PathNormalizer|null $pathNormalizer
   *   (optional) A replacement path normalizer collaborator. When NULL
   *   (default) the library derives its own from each operator's
   *   configuration — including the traversal posture the feature
   *   declaration selects.
   * @param array $operationDefaults
   *   (optional) Operation configuration merged under every build's
   *   own configuration (per-build wins per leaf).
   */
  public function __construct(
    protected readonly ?PathNormalizer $pathNormalizer = NULL,
    protected readonly array $operationDefaults = [],
  ) {
  }

  /**
   * Builds the operator over the given adapter and configuration.
   *
   * @param \League\Flysystem\FilesystemAdapter $adapter
   *   The adapter object produced by an adapter plugin; the builder
   *   never asks which backend it came from.
   * @param array $config
   *   The merged binding configuration (reserved posture and
   *   visibility keys included). The caller's array is not mutated.
   *
   * @return \League\Flysystem\FilesystemOperator
   *   A fresh configured operator; all storage access must route
   *   through it, never through the adapter object handed in.
   */
  public function build(FilesystemAdapter $adapter, array $config): FilesystemOperator {
    $options = NestedArray::mergeDeep($this->operationDefaults, $config);
    return new Filesystem($adapter, $options, $this->pathNormalizer);
  }

}
