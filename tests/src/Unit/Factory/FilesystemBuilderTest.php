<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Unit\Factory;

use Drupal\flysystem\Factory\FilesystemBuilder;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use League\Flysystem\PathNormalizer;
use League\Flysystem\PathTraversalDetected;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests the filesystem builder: composition with injected collaborators.
 *
 * The builder is the module's single seam where a League adapter object
 * and a merged configuration become a configured Filesystem operator:
 * the traversal posture arrives exactly as the library expresses it
 * (the operator's own path normalizer), collaborators may be replaced
 * through the builder's constructor, and nothing about the builder
 * knows or cares which backend produced the adapter object.
 * Memoization deliberately does NOT live here — that is the factory's
 * per-request concern; the builder is a pure composition step, so two
 * builds return two operators over the same adapter.
 */
#[Group('flysystem')]
#[CoversNothing]
final class FilesystemBuilderTest extends TestCase {

  /**
   * Builds the builder under test.
   *
   * @param \League\Flysystem\PathNormalizer|null $normalizer
   *   Optional collaborator override.
   * @param array $operationDefaults
   *   Defaults merged under every build's configuration.
   */
  private function builder(?PathNormalizer $normalizer = NULL, array $operationDefaults = []): FilesystemBuilder {
    return new FilesystemBuilder($normalizer, $operationDefaults);
  }

  /**
   * An adapter object plus config composes into a working operator.
   */
  public function testBuildProducesWorkingOperatorOverGivenAdapter(): void {
    $adapter = new InMemoryFilesystemAdapter();
    $operator = $this->builder()->build($adapter, []);
    self::assertInstanceOf(FilesystemOperator::class, $operator);
    $operator->write('composed.txt', 'through the builder');
    self::assertSame('through the builder', $operator->read('composed.txt'));
    self::assertSame(
      'through the builder',
      $adapter->read('composed.txt'),
      'The operator wraps the very adapter object handed in.'
    );
  }

  /**
   * Traversal posture lands exactly as the library expresses it.
   */
  public function testPostureKeyDrivesTheLibraryNormalizer(): void {
    $adapter = new InMemoryFilesystemAdapter();
    $adapter->write('deep/target.txt', 'v');

    $permissive = $this->builder()->build($adapter, ['allow_relative_path_traversal' => TRUE]);
    self::assertSame(
      'v',
      $permissive->read('deep/../deep/target.txt'),
      'Posture TRUE is the library default: interior .. resolves inside the root.'
    );

    $strict = $this->builder()->build($adapter, ['allow_relative_path_traversal' => FALSE]);
    $this->expectException(PathTraversalDetected::class);
    $strict->read('deep/../deep/target.txt');
  }

  /**
   * A constructor-injected normalizer replaces the posture default.
   */
  public function testInjectedNormalizerIsUsedByTheOperator(): void {
    $normalizer = new class() implements PathNormalizer {

      /**
       * Lowercases every path, standing in for a replaced collaborator.
       */
      public function normalizePath(string $path): string {
        return strtolower($path);
      }

    };
    $adapter = new InMemoryFilesystemAdapter();
    $operator = $this->builder($normalizer)->build($adapter, []);
    $operator->write('MIXEDCASE.txt', 'normalized');
    self::assertSame(
      'normalized',
      $adapter->read('mixedcase.txt'),
      'The injected collaborator, not the library default, normalized the path.'
    );
  }

  /**
   * Operation defaults sit under per-build configuration.
   */
  public function testOperationDefaultsMergeUnderBuildConfig(): void {
    $adapter = new InMemoryFilesystemAdapter();
    $builder = $this->builder(NULL, ['visibility' => 'private']);
    $operator = $builder->build($adapter, ['visibility' => 'public']);
    $operator->write('merged.txt', 'm');
    self::assertSame(
      'public',
      $adapter->visibility('merged.txt')->visibility(),
      'Per-build configuration wins over the builder defaults — the library merge order, preserved.'
    );
  }

  /**
   * The builder is pure: input untouched, no internal memoization.
   */
  public function testBuildIsPureComposition(): void {
    $adapter = new InMemoryFilesystemAdapter();
    $config = ['visibility' => 'public', 'allow_relative_path_traversal' => FALSE];
    $snapshot = $config;
    $first = $this->builder()->build($adapter, $config);
    $second = $this->builder()->build($adapter, $config);
    self::assertSame($config, $snapshot, 'Building must not mutate the caller\'s configuration array.');
    self::assertNotSame(
      $first,
      $second,
      'Memoization is the factory\'s per-request concern; the builder composes fresh every call.'
    );
  }

}
