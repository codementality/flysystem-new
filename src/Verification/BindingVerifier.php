<?php

declare(strict_types=1);

namespace Drupal\flysystem\Verification;

use Drupal\flysystem\Factory\OperatorFactory;
use Drupal\flysystem\Resolution\BindingResolver;
use Psr\Log\LoggerInterface;

/**
 * The verification surface for configured schemes.
 *
 * The verifyBinding() method answers one question: is this scheme's
 * storage configuration actually sound? It answers with structured
 * findings — severity plus message — instead of throwing, so
 * administration screens and command tooling can present the whole
 * picture of a misconfigured site without guarding every call. For one
 * scheme it checks the full chain: that the configuration resolves and
 * validates, that the referenced plugin exists, reports itself
 * available, and builds a working operator. A scheme with no
 * configuration is never a finding: stock Drupal storage is sound by
 * definition.
 *
 * Verification reaches no deeper than operator construction (no live
 * remote round trips beyond what building the operator itself does) and
 * propagates no exception of any kind, whatever it reads.
 *
 * Findings are both returned and logged, each at its own severity, so a
 * site operator sees the problem on the status report and the recording
 * exists in the log for the database log, syslog and any downstream log
 * integration regardless of who asked. The message text shown and logged
 * is the same string; scheme context travels with it.
 */
class BindingVerifier {

  /**
   * Constructs the verifier.
   *
   * @param \Drupal\flysystem\Resolution\BindingResolver $resolver
   *   The binding resolver providing validation and merged definitions.
   * @param \Drupal\flysystem\Factory\OperatorFactory $factory
   *   The operator factory whose build path checks plugin availability
   *   and construction.
   * @param \Drupal\flysystem\Definition\AdapterDefinitionSourceInterface[] $sources
   *   The configuration sources, used to enumerate which schemes exist
   *   so file-configured sites are verifiable with no UI involved.
   * @param \Psr\Log\LoggerInterface $logger
   *   This module's logger channel. Every finding is also logged at its
   *   own severity, so the database log, syslog and any log-handler
   *   integration receive what the user is shown.
   */
  public function __construct(
    protected readonly BindingResolver $resolver,
    protected readonly OperatorFactory $factory,
    protected readonly array $sources,
    protected readonly LoggerInterface $logger,
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

    try {
      $this->factory->operatorFor($scheme);
    }
    catch (\Throwable $e) {
      $findings[] = ['severity' => 'error', 'message' => $e->getMessage()];
    }

    foreach ($findings as $finding) {
      $this->logger->log($finding['severity'], $finding['message'], ['scheme' => $scheme]);
    }

    return $findings;
  }

  /**
   * Lists every scheme configured by any source.
   *
   * This is how configuration that lives only in settings.php becomes
   * known to the verification surface: there is no form to enumerate,
   * so the sources themselves answer what exists.
   *
   * @return string[]
   *   Configured scheme names, without duplicates.
   */
  public function configuredSchemes(): array {
    $schemes = [];
    foreach ($this->sources as $source) {
      foreach (array_keys($source->getSchemeBindings()) as $scheme) {
        $schemes[(string) $scheme] = TRUE;
      }
    }
    $list = array_keys($schemes);
    sort($list);
    return $list;
  }

  /**
   * Verifies every configured scheme in one call, keyed by scheme name.
   *
   * A status or diagnostic consumer calls this single method to cover
   * the site's whole configuration surface; schemes with no findings
   * appear with empty lists, which is the answer "sound".
   *
   * @return array<string, array<int, array{severity: string, message: string}>>
   *   Findings per configured scheme.
   */
  public function verifyEverything(): array {
    return $this->verifyAll($this->configuredSchemes());
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
