<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Unit\Verification;

use Psr\Log\LoggerInterface;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use League\Flysystem\Filesystem;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Component\Plugin\Discovery\DiscoveryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\flysystem\Plugin\FlysystemAdapter\Local;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\flysystem\Definition\ArrayDefinitionSource;
use Drupal\flysystem\Factory\OperatorFactory;
use Drupal\flysystem\Plugin\FlysystemAdapterBase;
use Drupal\flysystem\Plugin\FlysystemAdapterInterface;
use Drupal\flysystem\Plugin\FlysystemAdapterManager;
use Drupal\flysystem\Resolution\BindingResolver;
use Drupal\flysystem\Validation\SettingsValidator;
use Drupal\flysystem\Verification\BindingVerifier;
use Drupal\flysystem\Verification\PluginFeatureInspector;
use Drupal\flysystem_memory\Plugin\FlysystemAdapter\Memory;
use League\Flysystem\FilesystemOperator;
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
    $container = $this->createMock(ContainerInterface::class);
    $container
      ->method('getParameter')
      ->with('app.root')
      ->willReturn($this->webRoot);
    \Drupal::setContainer($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    \Drupal::setContainer($this->createMock(ContainerInterface::class));
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
    $inspector = new PluginFeatureInspector($manager);
    $validator = new SettingsValidator($this->webRoot, $inspector);
    $sourceObjects = array_map(static fn (array $layer): ArrayDefinitionSource => new ArrayDefinitionSource($layer), $sources);
    $resolver = new BindingResolver($sourceObjects, $validator);
    $factory = new OperatorFactory($resolver, $manager, $inspector, $this->logger);
    return new BindingVerifier($resolver, $factory, $sourceObjects, $this->logger);
  }

  /**
   * The discovery set: the shipped backends plus fixtures.
   */
  private function manager(): FlysystemAdapterManager {
    $definitions = [
      'local' => [
        'id' => 'local',
        // The shipped local backend under its production class name.
        'class' => Local::class,
      ],
      'memory' => ['id' => 'memory', 'class' => Memory::class],
      'absent_backend' => ['id' => 'absent_backend', 'class' => AbsentBackendFixture::class],
      'remote_like' => ['id' => 'remote_like', 'class' => RemoteLikeFixture::class],
    ];
    $manager = new FlysystemAdapterManager(
      new \ArrayObject(),
      $this->createMock(CacheBackendInterface::class),
      $this->createMock(ModuleHandlerInterface::class),
    );
    $discovery = new class ($definitions) implements DiscoveryInterface {

      public function __construct(private array $definitions) {
      }

      /**
       * Returns the definitions.
       *
       * @return array<string, array<string, string>>
       *   Definitions keyed by plugin id.
       */
      public function getDefinitions() {
        return $this->definitions;
      }

      /**
       * Returns one definition.
       *
       * @param string $plugin_id
       *   The plugin id.
       * @param bool $return_empty
       *   TRUE for NULL instead of throwing.
       *
       * @return array<string, string>|null
       *   The definition.
       */
      public function getDefinition($plugin_id, $return_empty = FALSE) {
        return $this->definitions[$plugin_id] ?? ($return_empty ? NULL : throw new PluginNotFoundException($plugin_id));
      }

      /**
       * Whether a definition exists.
       *
       * @param string $plugin_id
       *   The plugin id.
       *
       * @return bool
       *   TRUE when known.
       */
      public function hasDefinition($plugin_id) {
        return isset($this->definitions[$plugin_id]);
      }

    };
    $reflection = new \ReflectionObject($manager);
    while (!$reflection->hasProperty('discovery')) {
      $reflection = $reflection->getParentClass();
    }
    $property = $reflection->getProperty('discovery');
    $property->setAccessible(TRUE);
    $property->setValue($manager, $discovery);
    return $manager;
  }

  /**
   * A healthy local binding yields no findings at all.
   */
  public function testHealthyBindingProducesNoFindings(): void {
    $verifier = $this->verifier([
      [
        'adapters' => ['main' => ['plugin' => 'local', 'config' => ['root' => 'files']]],
        'bindings' => ['public' => ['adapter' => 'main']],
      ],
    ]);
    self::assertSame([], $verifier->verifyBinding('public'));
  }

  /**
   * An unbound, core-native scheme is not a problem.
   */
  public function testUnboundSchemeHasNoFindings(): void {
    $verifier = $this->verifier([['adapters' => [], 'bindings' => []]]);
    self::assertSame([], $verifier->verifyBinding('private'));
  }

  /**
   * An unknown plugin surfaces as an error finding naming it — no throw.
   */
  public function testUnknownPluginBecomesErrorFinding(): void {
    $verifier = $this->verifier([
      [
        'adapters' => ['m' => ['plugin' => 'ghost_plugin']],
        'bindings' => ['s' => ['adapter' => 'm']],
      ],
    ]);
    $findings = $verifier->verifyBinding('s');
    self::assertCount(1, $findings);
    self::assertSame('error', $findings[0]['severity']);
    self::assertStringContainsString('ghost_plugin', $findings[0]['message']);
  }

  /**
   * A plugin with a missing package surfaces a typed, naming finding.
   */
  public function testUnavailableBackendBecomesErrorFinding(): void {
    $verifier = $this->verifier([
      [
        'adapters' => ['m' => ['plugin' => 'absent_backend', 'config' => []]],
        'bindings' => ['s' => ['adapter' => 'm']],
      ],
    ]);
    $findings = $verifier->verifyBinding('s');
    self::assertCount(1, $findings);
    self::assertSame('error', $findings[0]['severity']);
    self::assertStringContainsString('absent_backend', $findings[0]['message']);
  }

  /**
   * A refused webroot containment becomes an error naming the path.
   */
  public function testContainmentViolationBecomesErrorFinding(): void {
    $verifier = $this->verifier([
      [
        'adapters' => ['m' => ['plugin' => 'local', 'config' => ['root' => '/srv/outside-webroot']]],
        'bindings' => ['public' => ['adapter' => 'm']],
      ],
    ]);
    $findings = $verifier->verifyBinding('public');
    self::assertNotEmpty($findings);
    self::assertSame('error', $findings[0]['severity']);
    self::assertStringContainsString('/srv/outside-webroot', $findings[0]['message']);
  }

  /**
   * Malformed configuration is reported, never thrown.
   */
  public function testMalformedSettingsBecomeFindingsNotExceptions(): void {
    $verifier = $this->verifier([
      ['adapters' => ['m' => 'not-an-array'], 'bindings' => ['public' => ['adapter' => 'm']]],
    ]);
    $findings = $verifier->verifyBinding('public');
    self::assertNotEmpty($findings);
    self::assertSame('error', $findings[0]['severity']);
  }

  /**
   * A batch verifies many schemes and keys findings per scheme.
   */
  public function testVerifyAllKeysFindingsByScheme(): void {
    $verifier = $this->verifier([
      [
        'adapters' => [
          'main' => ['plugin' => 'local', 'config' => ['root' => 'files']],
          'ghosty' => ['plugin' => 'no_such'],
        ],
        'bindings' => [
          'public' => ['adapter' => 'main'],
          'broken' => ['adapter' => 'ghosty'],
        ],
      ],
    ]);
    $report = $verifier->verifyAll(['public', 'broken', 'absent_here']);
    self::assertSame([], $report['public']);
    self::assertCount(1, $report['broken']);
    self::assertSame([], $report['absent_here']);
  }

  /**
   * Verifies exceptions never escape, and unbound garbage is not a problem.
   */
  public function testGarbageSchemeNameThrowsNeverAndUnboundStaysClean(): void {
    $unbound = $this->verifier([['adapters' => [], 'bindings' => []]]);
    self::assertSame([], $unbound->verifyBinding('has space'), 'A scheme nothing binds cannot misbehave; verification stays quiet.');

    $bound = $this->verifier([
      ['adapters' => [], 'bindings' => ['has space' => ['adapter' => 'anything']]],
    ]);
    $findings = $bound->verifyBinding('has space');
    self::assertSame('error', $findings[0]['severity'], 'A bound scheme with an illegal name surfaces as a finding, never as an exception.');
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
        'adapters' => ['a' => ['plugin' => 'remote_like', 'config' => []]],
        'bindings' => ['files' => ['adapter' => 'a'], 'cdn' => ['adapter' => 'a']],
      ],
      [
        'adapters' => ['b' => ['plugin' => 'remote_like', 'config' => []]],
        'bindings' => ['other' => ['adapter' => 'b']],
      ],
    ]);
    $schemes = $verifier->configuredSchemes();
    sort($schemes);
    self::assertSame(['cdn', 'files', 'other'], $schemes, 'Every scheme bound by any source is known to the verifier, file-configured or otherwise.');
  }

  /**
   * Verifying everything configured is one call, keyed by scheme.
   */
  public function testVerifyEverythingCoversAllConfiguredSchemes(): void {
    $verifier = $this->verifier([
      [
        'adapters' => [
          'good' => ['plugin' => 'local', 'config' => ['root' => 'files']],
          'ghost' => ['plugin' => 'no_such_plugin'],
        ],
        'bindings' => [
          'public' => ['adapter' => 'good'],
          'broken' => ['adapter' => 'ghost'],
        ],
      ],
    ]);
    $report = $verifier->verifyEverything();
    self::assertSame(['broken', 'public'], array_keys($report), 'Report is keyed by configured scheme, deterministically ordered.');
    self::assertSame([], $report['public']);
    self::assertSame('error', $report['broken'][0]['severity']);
  }

}

/**
 * A fixture plugin whose underlying package reports itself unavailable.
 */
final class AbsentBackendFixture extends FlysystemAdapterBase implements FlysystemAdapterInterface {

  /**
   * {@inheritdoc}
   */
  public function buildFilesystem(array $config = []): FilesystemOperator {
    throw new \LogicException('Must not be reached: availability is verified first.');
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return FALSE;
  }

}

/**
 * A fixture remote-style plugin with an in-memory operator.
 */
final class RemoteLikeFixture extends FlysystemAdapterBase implements FlysystemAdapterInterface {

  /**
   * {@inheritdoc}
   */
  public function buildFilesystem(array $config = []): FilesystemOperator {
    return new Filesystem(new InMemoryFilesystemAdapter());
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return TRUE;
  }

}
