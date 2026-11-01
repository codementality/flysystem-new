<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Traits;

use Drupal\flysystem\Exception\UnknownAdapterPluginException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\PathTraversalDetected;

/**
 * The adapter-agnostic assertion base for the filesystem service graph.
 *
 * Every host kernel test calls the full set through runGraphAssertions()
 * after configuring exactly one thing differently: which driver word its
 * scheme binding carries (and the marker-declared posture that follows).
 * The suite's meaning is this: if the same legs pass on a local-backed,
 * a memory-backed, and a contrib-fixture-backed operator, the graph is
 * genuinely adapter-agnostic — one word in the definition, zero code
 * changes anywhere else.
 */
trait AdapterAgnosticGraphAssertionsTrait {

  /**
   * Runs every shared leg for the configured scheme.
   *
   * @param bool $interiorTraversalResolves
   *   The posture expectation the adapter's feature declaration selects:
   *   TRUE for local-storage adapters (library-default interior '..'
   *   resolution), FALSE for strict adapters (rejected outright).
   */
  protected function runGraphAssertions(bool $interiorTraversalResolves): void {
    $this->legRoundTrip();
    $this->legMemoization();
    $this->legUnboundIsNative();
    $this->legUnknownDriverTypedError();
    $this->legLeadingEscapeThrows();
    $this->legPostureFollowsDeclaration($interiorTraversalResolves);
  }

  /**
   * The operator answers the basic storage legs through the graph.
   */
  protected function legRoundTrip(): void {
    $factory = $this->container->get('flysystem.operator_factory');
    $operator = $factory->operatorFor($this->swapScheme);
    self::assertInstanceOf(
      FilesystemOperator::class,
      $operator,
      'The graph produces a library FilesystemOperator for the bound scheme.'
    );
    $operator->write('graph/proof.txt', 'through the graph');
    self::assertSame(
      'through the graph',
      $operator->read('graph/proof.txt'),
      'Bytes round-trip on this backend.'
    );
    $paths = [];
    foreach ($operator->listContents('graph', FALSE) as $item) {
      $paths[] = $item->path();
    }
    self::assertSame(
      ['graph/proof.txt'],
      $paths,
      'Listing paths are complete from the bound root, as the library defines them — never host-directory artifacts.'
    );
    $operator->delete('graph/proof.txt');
    self::assertFalse($operator->fileExists('graph/proof.txt'));
  }

  /**
   * The same merged definition returns the identical operator instance.
   */
  protected function legMemoization(): void {
    $factory = $this->container->get('flysystem.operator_factory');
    self::assertSame(
      $factory->operatorFor($this->swapScheme),
      $factory->operatorFor($this->swapScheme),
      'Memoization is by definition identity, per request.'
    );
  }

  /**
   * A scheme with no binding builds nothing and changes nothing.
   */
  protected function legUnboundIsNative(): void {
    $factory = $this->container->get('flysystem.operator_factory');
    self::assertNull(
      $factory->operatorFor('graph-unbound'),
      'Unbound schemes resolve to the core-native NULL answer; the graph builds nothing for them.'
    );
  }

  /**
   * A binding to an unknown driver fails late, typed, naming the driver.
   */
  protected function legUnknownDriverTypedError(): void {
    $factory = $this->container->get('flysystem.operator_factory');
    try {
      $factory->operatorFor('graph-ghost');
      self::fail('A ghost driver must raise the typed unknown-plugin error when touched.');
    }
    catch (UnknownAdapterPluginException $e) {
      self::assertStringContainsString('ghost_driver', $e->getMessage(), 'The typed error names the offending driver.');
    }
  }

  /**
   * Root escape in a target throws on every backend — library-invariant.
   */
  protected function legLeadingEscapeThrows(): void {
    $factory = $this->container->get('flysystem.operator_factory');
    $this->expectException(PathTraversalDetected::class);
    $factory->operatorFor($this->swapScheme)->write('../never.txt', 'no');
  }

  /**
   * Interior '..' behaves exactly as the declared feature selects.
   */
  protected function legPostureFollowsDeclaration(bool $resolves): void {
    $factory = $this->container->get('flysystem.operator_factory');
    $operator = $factory->operatorFor($this->swapScheme);
    $operator->write('deep/target.txt', 'v');
    if ($resolves) {
      self::assertSame(
        'v',
        $operator->read('deep/../deep/target.txt'),
        'A local-storage declaration gets the library default: interior .. resolves inside the bound root.'
      );
    }
    else {
      $this->expectException(PathTraversalDetected::class);
      $operator->read('deep/../deep/target.txt');
    }
  }

  /**
   * The scheme this host test bound for the swap.
   *
   * @var string
   */
  protected string $swapScheme = '';

}
