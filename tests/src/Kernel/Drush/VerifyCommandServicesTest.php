<?php

declare(strict_types=1);

namespace Drupal\flysystem\Tests\Kernel\Drush;

use Consolidation\AnnotatedCommand\CommandFileDiscovery;
use Consolidation\AnnotatedCommand\CommandResult;
use Drupal\flysystem\Drush\Commands\FlysystemVerifyCommand;
use Drupal\flysystem\Verification\BindingVerifier;
use Drupal\Tests\flysystem\FlysystemBootTestTrait;
use Drupal\Tests\flysystem\FlysystemKernelTestTrait;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the verify command against the real services of a booted site.
 *
 * Three separate things must be true for this command to exist honestly:
 * it is constructible from the container's verification service and
 * nothing else; the findings it prints equal the findings the status
 * report renders for the same configuration (one checking surface, many
 * megaphones — a command that disagreed with the page would be a second
 * source of truth); and drush's own command-file discovery — the same
 * search recipe drush runs at boot — finds the class in the module.
 */
#[Group('flysystem')]
#[RunTestsInSeparateProcesses]
final class VerifyCommandServicesTest extends KernelTestBase {

  use FlysystemKernelTestTrait;
  use FlysystemBootTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['flysystem'];

  /**
   * Builds the command over the container's verifier service.
   */
  protected function command(): FlysystemVerifyCommand {
    $verifier = $this->container->get('flysystem.binding_verifier');
    self::assertInstanceOf(BindingVerifier::class, $verifier);
    return new FlysystemVerifyCommand($verifier);
  }

  /**
   * A sound binding yields a sound row naming the scheme.
   */
  public function testSoundBindingRendersSoundRow(): void {
    $this->rebuildWithFlysystemBindings([
      'public' => ['driver' => 'local', 'config' => ['root' => 'sites/default/files']],
    ]);

    $result = $this->command()->verify(NULL, ['format' => 'table', 'strict' => FALSE]);
    self::assertInstanceOf(CommandResult::class, $result);

    $rows = array_values($result->getOutputData()->getArrayCopy());
    $public = array_values(array_filter($rows, static fn(array $row): bool => $row['scheme'] === 'public'));
    self::assertNotEmpty($public, 'The configured scheme must appear in the output.');
    self::assertSame('sound', $public[0]['severity']);
  }

  /**
   * A broken binding yields a finding row instead of an exception.
   */
  public function testBrokenBindingBecomesFindingRow(): void {
    $this->rebuildWithFlysystemBindings(['public' => ['driver' => 'nosuchplugin']]);

    $result = $this->command()->verify(NULL, ['format' => 'table', 'strict' => FALSE]);
    self::assertSame(0, $result->getExitCode(), 'Reporting the problem is not itself a failure.');

    $rows = array_values($result->getOutputData()->getArrayCopy());
    self::assertSame('public', $rows[0]['scheme']);
    self::assertSame('error', $rows[0]['severity']);
  }

  /**
   * The command and the status report agree on the same configuration.
   *
   * With a planted misconfiguration, every finding the command prints
   * must appear as a problem on the status report entry — and every
   * problem on the entry must appear in the command's output.
   */
  public function testCommandOutputEqualsStatusReport(): void {
    $this->rebuildWithFlysystemBindings([
      'public' => ['driver' => 'nosuchplugin'],
      'temporary' => ['driver' => 'local', 'config' => ['root' => 'sites/default/files']],
    ]);

    $rows = $this->command()->verify(NULL, ['format' => 'table', 'strict' => FALSE])->getOutputData()->getArrayCopy();
    $command_lines = [];
    foreach ($rows as $row) {
      if ($row['severity'] !== 'sound') {
        $command_lines[] = $row['scheme'] . ': ' . $row['message'];
      }
    }

    /** @var \Drupal\flysystem\Verification\StatusReporter $reporter */
    $reporter = $this->container->get('flysystem.status_reporter');
    $entry = $reporter->buildEntry();
    self::assertIsArray($entry['value'], 'A broken site must render a problem list on the status report.');
    $report_lines = $entry['value']['#items'];

    sort($command_lines);
    sort($report_lines);
    self::assertSame($report_lines, $command_lines, 'The command must print exactly what the status report shows.');
  }

  /**
   * The command consumes only the public verification API.
   *
   * The class must declare the verifier as its single constructor
   * collaborator: no resolver, no factory, no settings reader of its own.
   */
  public function testNoSecondSourceOfTruth(): void {
    $constructor = (new \ReflectionClass(FlysystemVerifyCommand::class))->getConstructor();
    self::assertNotNull($constructor);
    $types = array_map(
      static fn(\ReflectionParameter $parameter): string => (string) $parameter->getType(),
      $constructor->getParameters(),
    );
    self::assertSame([BindingVerifier::class], array_values($types), 'The command may depend on nothing but the verification surface.');
  }

  /**
   * Drush's command discovery recipe finds this module's command file.
   *
   * The search settings mirror exactly what drush runs per module at
   * boot: src/Drush/ base, Commands/ one level deep, class names ending
   * in Command, namespace \\Drupal\\flysystem\\Drush. If this holds, the
   * command is reachable as a real console command without any drush
   * service file.
   */
  public function testDrushDiscoveryFindsTheCommand(): void {
    $discovery = new CommandFileDiscovery();
    $discovery
      ->setIncludeFilesAtBase(TRUE)
      ->setSearchDepth(1)
      ->ignoreNamespacePart('src')
      ->setSearchLocations(['Commands', 'Hooks'])
      ->setSearchPattern('#.*(Command|Hook)s?.php$#');

    $module_path = \Drupal::service('extension.list.module')->getPath('flysystem');
    $classes = $discovery->discover(
      [$module_path . '/src/Drush/'],
      "\\Drupal\\flysystem\\Drush",
    );

    // Discovery maps file paths to namespaced class names (with a leading
    // separator); normalize the values before comparing against ::class.
    $normalized = array_map(static fn(string $class): string => ltrim($class, '\\'), array_values($classes));
    self::assertContains(FlysystemVerifyCommand::class, $normalized, 'The command must be discoverable by drush at exactly the path and namespace drush searches.');
  }

}
