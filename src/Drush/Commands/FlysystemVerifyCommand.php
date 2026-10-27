<?php

declare(strict_types=1);

namespace Drupal\flysystem\Drush\Commands;

use Consolidation\AnnotatedCommand\CommandResult;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\flysystem\Verification\BindingVerifier;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Psr\Container\ContainerInterface;

/**
 * Prints the verification findings for every configured scheme.
 *
 * The command-line half of the verification surface: the same findings
 * the status report page shows and the log records, emitted as rows for
 * terminals, automation, and headless installs. All checking lives in
 * the verification service — this class only shapes what it answers, so
 * the page, the log, and the command can never disagree about the same
 * configuration.
 *
 * The command reports; it does not police. By default a run succeeds
 * even when findings exist, so pipelines that capture output decide for
 * themselves. The strict option is for deployment gates: it exits
 * nonzero only when an error-severity finding exists, letting warnings
 * stay reportable noise.
 */
final class FlysystemVerifyCommand extends DrushCommands {

  /**
   * The command name.
   */
  const VERIFY = 'flysystem:verify';

  /**
   * Constructs the command.
   *
   * @param \Drupal\flysystem\Verification\BindingVerifier $verifier
   *   The verification surface; the single collaborator, so the findings
   *   printed here are exactly the findings every other surface shows.
   */
  public function __construct(
    protected readonly BindingVerifier $verifier,
  ) {
    parent::__construct();
  }

  /**
   * Builds the command with the site's verification service.
   *
   * Called by the command loader once Drupal is bootstrapped. The
   * command exists only when the verification service does; the
   * container is consulted by id, and the class depends on nothing but
   * the public verification surface.
   *
   * @param \Psr\Container\ContainerInterface $container
   *   A container holding the site's services.
   *
   * @return static|null
   *   The command, or NULL when no verification service is available.
   */
  public static function create(ContainerInterface $container): ?self {
    if ($container->has('flysystem.binding_verifier')) {
      return new self($container->get('flysystem.binding_verifier'));
    }
    return NULL;
  }

  /**
   * Check whether each configured file path's storage settings actually work.
   *
   * Answers one question per scheme configured in settings.php: can this
   * module really use what the entry says? It checks that the entry is
   * well-formed, that the named storage type exists and can load, that a
   * publicly served path stays inside the web folder, and that the storage
   * object for the path can be assembled. It uploads nothing, downloads
   * nothing, and contacts no remote service - a dry run of the settings,
   * not a live file test.
   *
   * Why it matters: a broken path is invisible by design until the first
   * file operation hits it (that is what keeps the rest of the site alive
   * when one scheme is misconfigured). This check surfaces those errors
   * before real users trip over them - typically right after editing
   * settings.php or as a deploy step.
   *
   * The command reports; it does not police. Every scheme gets one row:
   * "sound" with no message, or one row per finding with the exact problem
   * and its severity. By default the run succeeds even when findings
   * exist, so capturing the output never fails a pipeline by accident;
   * --strict exits with a failure status when any error-severity finding
   * exists (warnings alone still pass). The same findings appear on the
   * status report page and in the watchdog log; this command is their
   * command-line face, not a second checker, so the three can never
   * disagree.
   *
   * @param string|string[]|null $schemes
   *   Schemes to check; every configured scheme when empty. A single
   *   scheme arrives from the command line as a string, several as an
   *   array.
   * @param array<string, mixed> $options
   *   Command options, including 'strict'.
   *
   * @return \Consolidation\AnnotatedCommand\CommandResult
   *   Rows carrying each scheme's state, with the run's exit code.
   */
  #[CLI\Command(name: self::VERIFY)]
  #[CLI\Argument(name: 'schemes', description: 'Optional list of schemes to verify instead of every configured one.')]
  #[CLI\Option(name: 'strict', description: 'Exit with a failure status when any error-severity finding exists.')]
  #[CLI\Usage(name: 'drush flysystem:verify', description: 'Show all findings for all configured schemes.')]
  #[CLI\Usage(name: 'drush flysystem:verify --format=json', description: 'Emit machine-readable findings.')]
  #[CLI\Usage(name: 'drush flysystem:verify --strict public private', description: 'Fail the run if those two schemes show errors.')]
  #[CLI\FieldLabels(labels: [
    'scheme' => 'Scheme',
    'severity' => 'Severity',
    'message' => 'Finding',
  ])]
  #[CLI\DefaultTableFields(fields: ['scheme', 'severity', 'message'])]
  public function verify($schemes = NULL, array $options = ['format' => 'table', 'strict' => FALSE]): CommandResult {
    $schemes = $this->normalizeSchemes($schemes);
    $report = $schemes !== []
      ? $this->verifier->verifyAll($schemes)
      : $this->verifier->verifyEverything();

    $rows = [];
    $has_error = FALSE;
    foreach ($report as $scheme => $findings) {
      if ($findings === []) {
        $rows[] = ['scheme' => (string) $scheme, 'severity' => 'sound', 'message' => ''];
        continue;
      }
      foreach ($findings as $finding) {
        $has_error = $has_error || $finding['severity'] === 'error';
        $rows[] = [
          'scheme' => (string) $scheme,
          'severity' => $finding['severity'],
          'message' => $finding['message'],
        ];
      }
    }

    if ($rows === []) {
      $rows[] = ['scheme' => '', 'severity' => 'sound', 'message' => 'No schemes are configured.'];
    }

    $exit_code = (!empty($options['strict']) && $has_error) ? self::EXIT_FAILURE : self::EXIT_SUCCESS;
    return CommandResult::dataWithExitCode(new RowsOfFields($rows), $exit_code);
  }

  /**
   * Normalizes the schemes argument to a clean list.
   *
   * Drush hands a single positional value as a plain string and repeated
   * values as an array; direct callers may pass either or NULL. The
   * result is the list of named schemes, empty when none were given.
   *
   * @param string|string[]|null $schemes
   *   The raw argument value.
   *
   * @return string[]
   *   The named schemes.
   */
  private function normalizeSchemes($schemes): array {
    if (is_string($schemes) && $schemes !== '') {
      return [$schemes];
    }
    if (is_array($schemes)) {
      return array_values(array_filter(array_map(static fn($scheme): string => trim((string) $scheme), $schemes), static fn(string $scheme): bool => $scheme !== ''));
    }
    return [];
  }

}
