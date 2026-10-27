<?php

declare(strict_types=1);

namespace Drupal\flysystem\Verification;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Builds this module's section of the core status report page.
 *
 * The status report is where a site builder — including one whose entire
 * configuration lives in settings.php and has no administration form —
 * learns what is wrong and what to fix. The section reports every
 * configured scheme; findings are rendered with their messages verbatim
 * (they are also logged by the verification surface itself). A site with
 * no Flysystem configuration is reported as such; stock storage is not a
 * problem to warn about.
 */
final class StatusReporter {

  use StringTranslationTrait;

  /**
   * Constructs the reporter.
   *
   * @param \Drupal\flysystem\Verification\BindingVerifier $verifier
   *   The verification surface whose findings populate the section.
   */
  public function __construct(
    protected readonly BindingVerifier $verifier,
  ) {
  }

  /**
   * Returns the status report entry for this module.
   *
   * @return array{title: mixed, value: mixed, severity: \Drupal\Core\Extension\Requirement\RequirementSeverity, weight: int}
   *   A runtime requirements entry keyed 'flysystem' by the caller.
   */
  public function buildEntry(): array {
    $schemes = $this->verifier->configuredSchemes();
    if ($schemes === []) {
      return [
        'title' => $this->t('Flysystem file storage'),
        'value' => $this->t("No Flysystem-backed schemes are configured; all file schemes use Drupal's standard storage."),
        'severity' => RequirementSeverity::OK,
        'weight' => 35,
      ];
    }

    $report = $this->verifier->verifyEverything();
    $problems = [];
    $warning = FALSE;
    foreach ($report as $scheme => $findings) {
      foreach ($findings as $finding) {
        if ($finding['severity'] === 'error') {
          $problems[] = $scheme . ': ' . $finding['message'];
        }
        elseif ($finding['severity'] === 'warning' && !in_array($scheme . ': ' . $finding['message'], $problems, TRUE)) {
          $warning = TRUE;
          $problems[] = $scheme . ': ' . $finding['message'];
        }
      }
    }

    if ($problems === []) {
      return [
        'title' => $this->t('Flysystem file storage'),
        'value' => $this->t('All configured Flysystem schemes verified without problems: @schemes.', ['@schemes' => implode(', ', $schemes)]),
        'severity' => RequirementSeverity::OK,
        'weight' => 35,
      ];
    }

    return [
      'title' => $this->t('Flysystem file storage'),
      'value' => [
        '#theme' => 'item_list',
        '#items' => $problems,
      ],
      'severity' => ($warning ? RequirementSeverity::Warning : RequirementSeverity::Error),
      'weight' => 35,
    ];
  }

}
