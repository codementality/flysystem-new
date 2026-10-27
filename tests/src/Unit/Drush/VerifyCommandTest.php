<?php

declare(strict_types=1);

namespace Drupal\flysystem\Tests\Unit\Drush;

use Drupal\flysystem\Drush\Commands\FlysystemVerifyCommand;
use Drupal\flysystem\Verification\BindingVerifier;
use Consolidation\AnnotatedCommand\CommandResult;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests the verify command's output shaping and exit code policy.
 *
 * The command is a thin surface over the verification API: it renders
 * exactly the findings that surface answers with — one row per scheme for
 * sound schemes, one row per finding otherwise — and by default always
 * reports success, because it reports problems rather than policing them.
 * Only the strict mode requested for deployment pipelines turns findings
 * into a nonzero exit code.
 */
#[Group('flysystem')]
#[CoversNothing]
final class VerifyCommandTest extends TestCase {

  /**
   * Builds the command over a verifier double with a fixed report.
   */
  private function command(array $report): FlysystemVerifyCommand {
    $verifier = $this->createMock(BindingVerifier::class);
    $verifier->method('verifyEverything')->willReturn($report);
    return new FlysystemVerifyCommand($verifier);
  }

  /**
   * Extracts the row data carried by the command result.
   */
  private function rowsOf(CommandResult $result): RowsOfFields {
    $data = $result->getOutputData();
    self::assertInstanceOf(RowsOfFields::class, $data);
    return $data;
  }

  /**
   * Sound schemes render one row each and the run reports success.
   */
  public function testSoundSchemesRenderOneRowEach(): void {
    $command = $this->command([
      'public' => [],
      'temporary' => [],
    ]);

    $result = $this->rowsOf($command->verify(['format' => 'table', 'strict' => FALSE]));

    $rows = array_values($result->getArrayCopy());
    self::assertCount(2, $rows);
    self::assertSame('public', $rows[0]['scheme']);
    self::assertSame('sound', $rows[0]['severity']);
    self::assertSame('temporary', $rows[1]['scheme']);
  }

  /**
   * Findings render verbatim, one row per finding, scheme attached.
   */
  public function testFindingsRenderVerbatimWithSchemeContext(): void {
    $command = $this->command([
      'public' => [
        ['severity' => 'error', 'message' => "Adapter 'public' references unknown plugin 'nosuchplugin'."],
      ],
      'backup' => [
        ['severity' => 'warning', 'message' => 'First problem.'],
        ['severity' => 'warning', 'message' => 'Second problem.'],
      ],
    ]);

    $rows = array_values($this->rowsOf($command->verify(['format' => 'table', 'strict' => FALSE]))->getArrayCopy());

    self::assertCount(3, $rows);
    self::assertSame('public', $rows[0]['scheme']);
    self::assertSame('error', $rows[0]['severity']);
    self::assertSame("Adapter 'public' references unknown plugin 'nosuchplugin'.", $rows[0]['message']);
    self::assertSame(['backup', 'warning', 'First problem.'], array_values($rows[1]));
    self::assertSame('Second problem.', $rows[2]['message']);
  }

  /**
   * A site with nothing configured reports that plainly and still succeeds.
   */
  public function testEmptyConfigurationSucceedsWithOneStatement(): void {
    $command = $this->command([]);

    $result = $command->verify(['format' => 'table', 'strict' => TRUE]);
    $rows = array_values($this->rowsOf($result)->getArrayCopy());

    self::assertSame(0, $result->getExitCode(), 'Reporting no findings is success even in strict mode.');
    self::assertCount(1, $rows);
    self::assertSame('', $rows[0]['scheme']);
    self::assertSame('sound', $rows[0]['severity']);
    self::assertStringContainsString('no schemes are configured', strtolower($rows[0]['message']));
  }

  /**
   * Findings do not fail the run by default: the command reports.
   */
  public function testFindingsDoNotChangeExitCodeByDefault(): void {
    $command = $this->command([
      'public' => [['severity' => 'error', 'message' => 'Broken.']],
    ]);

    self::assertSame(0, $command->verify(['format' => 'table', 'strict' => FALSE])->getExitCode());
  }

  /**
   * Strict mode exits nonzero when any error finding exists.
   */
  public function testStrictModeReflectsErrorSeverity(): void {
    $withError = $this->command([
      'public' => [['severity' => 'error', 'message' => 'Broken.']],
    ]);
    self::assertSame(1, $withError->verify(['format' => 'table', 'strict' => TRUE])->getExitCode());

    $warningsOnly = $this->command([
      'public' => [['severity' => 'warning', 'message' => 'Noted.']],
    ]);
    self::assertSame(0, $warningsOnly->verify(['format' => 'table', 'strict' => TRUE])->getExitCode(), 'Strict mode gates on errors; warnings are reportable noise.');
  }

}
