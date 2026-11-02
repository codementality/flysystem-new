<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Unit\Verification;

use Psr\Log\LoggerInterface;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use League\Flysystem\FilesystemAdapter;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\flysystem\Adapter\FlysystemAdapterBase;
use Drupal\flysystem\Adapter\FlysystemAdapterManager;
use Drupal\flysystem\Definition\ArrayDefinitionSource;
use Drupal\flysystem\Plugin\FlysystemAdapter\Local;
use Drupal\flysystem\Factory\FilesystemBuilder;
use Drupal\flysystem\Factory\OperatorFactory;
use Drupal\flysystem\Resolution\BindingResolver;
use Drupal\flysystem\Validation\SettingsValidator;
use Drupal\flysystem\Verification\AdapterFeatureInspector;
use Drupal\flysystem\Verification\BindingVerifier;
use Drupal\flysystem_memory\Plugin\FlysystemAdapter\InMemory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests the programmatic verification API for configured scheme bindings.
 *
 * VerifyBinding() answers "is this scheme's configuration actually sound?"
 * with structured findings instead of exceptions — the same surface later
 * admin screens and command-line tooling will consume. Every failure mode
 * has a finding shape: unknown plugin, unavailable backend package, an
 * invalid or refused root, structural settings errors, a shadowed
 * binding, and the policy flag for the testing-only memory backend.
 *
 * Two contract halves matter as much as the findings themselves:
 * verification must never throw, whatever it reads, and it performs no
 * deeper than construction-level checks (no live remote calls beyond what
 * building the operator itself requires).
 */
#[Group('flysystem')]
#[CoversNothing]
final class BindingVerifierTest extends TestCase {

  /**
   * Per-test temp directory.
   *
   * @var string
   */
  private string $tempRoot = '';

  /**
   * Logger channel double; findings must land here at their own severity.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  private $logger;

  /**
   * The mock app root.
   *
   * @var string
   */
  private string $webRoot = '';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->logger = $this->createMock(LoggerInterface::class);
    $this->tempRoot = sys_get_temp_dir() . '/flysystem-m111-' . getmypid();
    $this->webRoot = $this->tempRoot . '/web';
    mkdir($this->webRoot, 0777, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    exec('rm -rf ' . escapeshellarg($this->tempRoot));
    parent::tearDown();
  }

  /**
   * Wires the whole pipeline over given sources and a discovery set.
   *
   * @param array[] $sources
   *   Definition layers, highest precedence first.
   *
   * @return \Drupal\flysystem\Verification\BindingVerifier
   *   The verifier under test.
   */
  private function verifier(array $sources): BindingVerifier {
    $manager = $this->manager();
    $inspector = new AdapterFeatureInspector($manager);
    $validator = new SettingsValidator($this->webRoot, $inspector);
    $sourceObjects = array_map(static fn (array $layer): ArrayDefinitionSource => new ArrayDefinitionSource($layer), $sources);
    $resolver = new BindingResolver($sourceObjects, $validator);
    $factory = new OperatorFactory(
      $resolver,
      $manager,
      new FilesystemBuilder(),
      $inspector,
      $this->logger
    );
    return new BindingVerifier($resolver, $factory, $sourceObjects, $this->logger);
  }

  /**
   * The manager's definitions: shipped backends plus fixtures, as built.
   *
   * A manager double standing in for the plugin manager service; the
   * definition set mirrors attribute discovery and instantiation builds
   * the real plugin classes with the caller's configuration.
   */
  private function manager(): FlysystemAdapterManager {
    $definitions = [
      'local' => [
        'id' => 'local',
        'label' => 'Local files',
        'class' => Local::class,
        'provider' => 'flysystem',
      ],
      'in_memory' => [
        'id' => 'in_memory',
        'label' => 'In-memory (testing only)',
        'class' => InMemory::class,
        'provider' => 'flysystem_memory',
      ],
      'absent_backend' => [
        'id' => 'absent_backend',
        'label' => 'Absent backend',
        'class' => AbsentBackendFixture::class,
        'provider' => 'flysystem',
      ],
      'remote_like' => [
        'id' => 'remote_like',
        'label' => 'Remote-like fixture',
        'class' => RemoteLikeFixture::class,
        'provider' => 'flysystem',
      ],
    ];

    $manager = $this->createMock(FlysystemAdapterManager::class);
    $manager->method('getDefinitions')->willReturn($definitions);
    $manager->method('hasDefinition')->willReturnCallback(
      static fn (string $id): bool => isset($definitions[$id])
    );
    $manager->method('getDefinition')->willReturnCallback(
      static fn (string $id): ?array => $definitions[$id] ?? NULL
    );
    $manager->method('createInstance')->willReturnCallback(
      function (string $id, array $configuration = []) use ($definitions) {
        $definition = $definitions[$id] ?? NULL;
        return match ($id) {
          'local' => new Local(
            $this->webRoot,
            $configuration,
            $id,
            $definition
          ),
          'in_memory' => new InMemory(
            $configuration,
            $id,
            $definition
          ),
          'absent_backend' => new AbsentBackendFixture(
            $configuration,
            $id,
            $definition
          ),
          'remote_like' => new RemoteLikeFixture(
            $configuration,
            $id,
            $definition
          ),
          default => throw new PluginNotFoundException($id),
        };
      }
    );
    return $manager;
  }

  /**
   * A healthy local binding yields no findings at all.
   */
  public function testHealthyBindingProducesNoFindings(): void {
    $verifier = $this->verifier([
      [
        'adapters' => ['main' => ['adapter' => 'local', 'config' => ['root' => 'files']]],
        'bindings' => ['public' => ['adapter' => 'main']],
      ],
    ]);
    $this->assertSame([], $verifier->verifyBinding('public'));
  }

  /**
   * An unbound, core-native scheme is not a problem.
   */
  public function testUnboundSchemeHasNoFindings(): void {
    $verifier = $this->verifier([['adapters' => [], 'bindings' => []]]);
    $this->assertSame([], $verifier->verifyBinding('private'));
  }

  /**
   * An unknown plugin surfaces as an error finding naming it — no throw.
   */
  public function testUnknownPluginBecomesErrorFinding(): void {
    $verifier = $this->verifier([
      [
        'adapters' => ['m' => ['adapter' => 'ghost_plugin']],
        'bindings' => ['s' => ['adapter' => 'm']],
      ],
    ]);
    $findings = $verifier->verifyBinding('s');
    $this->assertCount(1, $findings);
    $this->assertSame('error', $findings[0]['severity']);
    $this->assertStringContainsString('ghost_plugin', $findings[0]['message']);
  }

  /**
   * A plugin with a missing package surfaces a typed, naming finding.
   */
  public function testUnavailableBackendBecomesErrorFinding(): void {
    $verifier = $this->verifier([
      [
        'adapters' => ['m' => ['adapter' => 'absent_backend', 'config' => []]],
        'bindings' => ['s' => ['adapter' => 'm']],
      ],
    ]);
    $findings = $verifier->verifyBinding('s');
    $this->assertCount(1, $findings);
    $this->assertSame('error', $findings[0]['severity']);
    $this->assertStringContainsString('absent_backend', $findings[0]['message']);
  }

  /**
   * A refused webroot containment becomes an error naming the path.
   */
  public function testContainmentViolationBecomesErrorFinding(): void {
    $verifier = $this->verifier([
      [
        'adapters' => ['m' => ['adapter' => 'local', 'config' => ['root' => '/srv/outside-webroot']]],
        'bindings' => ['public' => ['adapter' => 'm']],
      ],
    ]);
    $findings = $verifier->verifyBinding('public');
    $this->assertNotEmpty($findings);
    $this->assertSame('error', $findings[0]['severity']);
    $this->assertStringContainsString('/srv/outside-webroot', $findings[0]['message']);
  }

  /**
   * Malformed configuration is reported, never thrown.
   */
  public function testMalformedSettingsBecomeFindingsNotExceptions(): void {
    $verifier = $this->verifier([
      ['adapters' => ['m' => 'not-an-array'], 'bindings' => ['public' => ['adapter' => 'm']]],
    ]);
    $findings = $verifier->verifyBinding('public');
    $this->assertNotEmpty($findings);
    $this->assertSame('error', $findings[0]['severity']);
  }

  /**
   * A batch verifies many schemes and keys findings per scheme.
   */
  public function testVerifyAllKeysFindingsByScheme(): void {
    $verifier = $this->verifier([
      [
        'adapters' => [
          'main' => ['adapter' => 'local', 'config' => ['root' => 'files']],
          'ghosty' => ['adapter' => 'no_such'],
        ],
        'bindings' => [
          'public' => ['adapter' => 'main'],
          'broken' => ['adapter' => 'ghosty'],
        ],
      ],
    ]);
    $report = $verifier->verifyAll(['public', 'broken', 'absent_here']);
    $this->assertSame([], $report['public']);
    $this->assertCount(1, $report['broken']);
    $this->assertSame([], $report['absent_here']);
  }

  /**
   * Verifies exceptions never escape, and unbound garbage is not a problem.
   */
  public function testGarbageSchemeNameThrowsNeverAndUnboundStaysClean(): void {
    $unbound = $this->verifier([['adapters' => [], 'bindings' => []]]);
    $this->assertSame([], $unbound->verifyBinding('has space'), 'A scheme nothing binds cannot misbehave; verification stays quiet.');

    $bound = $this->verifier([
      ['adapters' => [], 'bindings' => ['has space' => ['adapter' => 'anything']]],
    ]);
    $findings = $bound->verifyBinding('has space');
    $this->assertSame('error', $findings[0]['severity'], 'A bound scheme with an illegal name surfaces as a finding, never as an exception.');
  }

  /**
   * Verifies configured schemes are enumerable from the configuration.
   *
   * A site configured entirely in settings.php has no form to enumerate;
   * the sources themselves answer what exists.
   */
  public function testConfiguredSchemesAreListedAcrossSources(): void {
    $verifier = $this->verifier([
      [
        'adapters' => ['a' => ['adapter' => 'remote_like', 'config' => []]],
        'bindings' => ['files' => ['adapter' => 'a'], 'cdn' => ['adapter' => 'a']],
      ],
      [
        'adapters' => ['b' => ['adapter' => 'remote_like', 'config' => []]],
        'bindings' => ['other' => ['adapter' => 'b']],
      ],
    ]);
    $schemes = $verifier->configuredSchemes();
    sort($schemes);
    $this->assertSame(['cdn', 'files', 'other'], $schemes, 'Every scheme bound by any source is known to the verifier, file-configured or otherwise.');
  }

  /**
   * Verifying everything configured is one call, keyed by scheme.
   */
  public function testVerifyEverythingCoversAllConfiguredSchemes(): void {
    $verifier = $this->verifier([
      [
        'adapters' => [
          'good' => ['adapter' => 'local', 'config' => ['root' => 'files']],
          'ghost' => ['adapter' => 'no_such_plugin'],
        ],
        'bindings' => [
          'public' => ['adapter' => 'good'],
          'broken' => ['adapter' => 'ghost'],
        ],
      ],
    ]);
    $report = $verifier->verifyEverything();
    $this->assertSame(['broken', 'public'], array_keys($report), 'Report is keyed by configured scheme, deterministically ordered.');
    $this->assertSame([], $report['public']);
    $this->assertSame('error', $report['broken'][0]['severity']);
  }

}

/**
 * A fixture backend whose package is not installed.
 */
final class AbsentBackendFixture extends FlysystemAdapterBase {

  /**
   * {@inheritdoc}
   */
  public function adapter(): FilesystemAdapter {
    throw new \LogicException('Unreachable: availability must be refused before building.');
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return FALSE;
  }

}

/**
 * A fixture remote-style adapter with an in-memory object store.
 */
final class RemoteLikeFixture extends FlysystemAdapterBase {

  /**
   * {@inheritdoc}
   */
  public function adapter(): FilesystemAdapter {
    return new InMemoryFilesystemAdapter();
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return TRUE;
  }

}
