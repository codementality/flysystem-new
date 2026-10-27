<?php

declare(strict_types=1);

namespace Drupal\flysystem\Verification;

use Drupal\flysystem\Factory\OperatorFactory;
use Drupal\flysystem\Resolution\BindingResolver;

/**
 * The programmatic verification surface for configured scheme bindings.
 *
 * The verifyBinding() method answers one question: is this scheme's
 * configuration actually sound? It answers with structured findings —
 * severity plus message — instead of
 * throwing, so administration screens and command tooling can present the
 * whole picture of a misconfigured site without guarding every call. It
 * checks the full chain for one scheme: that the configuration resolves
 * and validates, that the referenced plugin exists and reports itself
 * available, and the policy rule that the testing-only memory backend is
 * never a production binding. A scheme bound by more than one source gets
 * an informational note that its lower binding is shadowed.
 *
 * Verification reaches no deeper than operator construction (no live
 * remote round trips beyond what building the operator itself does) and
 * propagates no exception of any kind, whatever it reads.
 */
final class BindingVerifier {

  /**
   * Constructs the verifier.
   *
   * @param \Drupal\flysystem\Resolution\BindingResolver $resolver
   *   The binding resolver providing validation and merged definitions.
   * @param \Drupal\flysystem\Factory\OperatorFactory $factory
   *   The operator factory whose build path checks plugin availability
   *   and construction.
   * @param \Drupal\flysystem\Definition\AdapterDefinitionSourceInterface[] $sources
   *   The same ordered sources the resolver reads, used only to detect
   *   shadowed bindings.
   */
  public function __construct(
    protected readonly BindingResolver $resolver,
    protected readonly OperatorFactory $factory,
    protected readonly array $sources = [],
  ) {
  }

  /**
   * Verifies one scheme's binding; findings are never exceptions.
   *
   * @param string $scheme
   *   The scheme to verify.
   *
   * @return array<int, array{severity: string, message: string}>
   *   Findings, empty when the binding is sound (or the scheme is
   *   unbound and therefore core-native, which is not a problem).
   */
  public function verifyBinding(string $scheme): array {
    $findings = [];

    $shadowed = 0;
    foreach ($this->sources as $source) {
      if (array_key_exists($scheme, $source->getSchemeBindings())) {
        $shadowed++;
      }
    }
    if ($shadowed > 1) {
      $findings[] = [
        'severity' => 'info',
        'message' => sprintf("Scheme '%s' is bound by more than one configuration source; the highest-precedence binding applies and the others are shadowed.", $scheme),
      ];
    }

    try {
      $definition = $this->resolver->resolve($scheme);
    }
    catch (\Throwable $e) {
      $findings[] = ['severity' => 'error', 'message' => $e->getMessage()];
      return $findings;
    }
    if ($definition === NULL) {
      return $findings;
    }

    if ($definition['plugin'] === 'memory') {
      $findings[] = [
        'severity' => 'warning',
        'message' => sprintf("Scheme '%s' uses the 'memory' adapter, which is a testing-only backend and must not be configured for production storage.", $scheme),
      ];
    }

    try {
      $this->factory->operatorFor($scheme);
    }
    catch (\Throwable $e) {
      $findings[] = ['severity' => 'error', 'message' => $e->getMessage()];
    }

    return $findings;
  }

  /**
   * Verifies several schemes at once, keyed by scheme name.
   *
   * @param string[] $schemes
   *   The schemes to verify.
   *
   * @return array<string, array<int, array{severity: string, message: string}>>
   *   Findings per scheme.
   */
  public function verifyAll(array $schemes): array {
    $report = [];
    foreach ($schemes as $scheme) {
      $report[$scheme] = $this->verifyBinding((string) $scheme);
    }
    return $report;
  }

}
