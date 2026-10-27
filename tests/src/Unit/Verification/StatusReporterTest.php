<?php

declare(strict_types=1);

namespace Drupal\flysystem\Tests\Unit\Verification;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\flysystem\Verification\BindingVerifier;
use Drupal\flysystem\Verification\StatusReporter;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Tests the status report section built from verification findings.
 */
#[Group('flysystem')]
#[CoversNothing]
final class StatusReporterTest extends TestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $translation = $this->createMock(TranslationInterface::class);
    $translation
      ->method('translate')
      ->willReturnArgument(0);
    $translation
      ->method('translateString')
      ->willReturnCallback(static fn (TranslatableMarkup $markup): string => $markup->getUntranslatedString());
    $container = $this->createMock(ContainerInterface::class);
    $container->method('get')->with('string_translation')->willReturn($translation);
    \Drupal::setContainer($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    \Drupal::setContainer($this->createMock(ContainerInterface::class));
    parent::tearDown();
  }

  /**
   * Builds the reporter over a stubbed verification surface.
   *
   * @param string[] $schemes
   *   Configured schemes the verifier should list.
   * @param array<string, list<array{severity: string, message: string}>> $report
   *   Findings per scheme.
   */
  private function reporter(array $schemes, array $report): StatusReporter {
    $verifier = $this->createMock(BindingVerifier::class);
    $verifier->method('configuredSchemes')->willReturn($schemes);
    $verifier->method('verifyEverything')->willReturn($report);
    return new StatusReporter($verifier);
  }

  /**
   * A site with no Flysystem configuration is reported, calmly.
   */
  public function testUnconfiguredSiteReportsOkAndSaysSo(): void {
    $entry = $this->reporter([], [])->buildEntry();
    self::assertSame(RequirementSeverity::OK, $entry['severity']);
    self::assertStringContainsString('No Flysystem-backed schemes', (string) $entry['value']->getUntranslatedString());
  }

  /**
   * Healthy configuration lists its schemes and reports OK.
   */
  public function testCleanConfigurationReportsOkWithSchemes(): void {
    $entry = $this->reporter(['public'], ['public' => []])->buildEntry();
    self::assertSame(RequirementSeverity::OK, $entry['severity']);
    self::assertInstanceOf(TranslatableMarkup::class, $entry['value']);
    self::assertStringContainsString('@schemes', $entry['value']->getUntranslatedString());
  }

  /**
   * An error finding drives error severity and names the scheme.
   */
  public function testErrorFindingDrivesErrorSeverity(): void {
    $entry = $this->reporter(['broken'], [
      'broken' => [['severity' => 'error', 'message' => 'The adapter plugin no_such is not available.']],
    ])->buildEntry();
    self::assertSame(RequirementSeverity::Error, $entry['severity']);
    $rendered = implode('; ', $entry['value']['#items']);
    self::assertStringContainsString('broken', $rendered);
    self::assertStringContainsString('no_such', $rendered);
  }

  /**
   * A warning-only finding warns without sounding an alarm.
   */
  public function testWarningFindingsKeepWarningSeverity(): void {
    $entry = $this->reporter(['sandbox'], [
      'sandbox' => [['severity' => 'warning', 'message' => 'Something to look at.']],
    ])->buildEntry();
    self::assertSame(RequirementSeverity::Warning, $entry['severity']);
  }

}
