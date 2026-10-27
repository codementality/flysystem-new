<?php

declare(strict_types=1);

namespace Drupal\flysystem\Tests\Unit\Plugin;

use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Component\Plugin\Discovery\DiscoveryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\flysystem\Exception\UnknownAdapterPluginException;
use Drupal\flysystem\Definition\ArrayDefinitionSource;
use Drupal\flysystem\Factory\OperatorFactory;
use Drupal\flysystem\Plugin\FlysystemAdapter\Local;
use Drupal\flysystem\Plugin\FlysystemAdapter\Memory;
use Drupal\flysystem\Plugin\FlysystemAdapterInterface;
use Drupal\flysystem\Plugin\FlysystemAdapterManager;
use Drupal\flysystem\Plugin\LocalStorageInterface;
use Drupal\flysystem\Resolution\BindingResolver;
use Drupal\flysystem\Validation\SettingsValidator;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\PathTraversalDetected;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Tests for the memory adapter plugin: a second backend, zero new branching.
 *
 * This plugin exists for one reason — the test suite must be able to prove
 * factory behavior (identity, memoization, posture, error paths) against
 * more than one backend through the same interfaces, so nothing quietly
 * tests "local" where it means "any adapter". It is never user-selectable
 * and never a production backend: interfaces and admin UIs omit it, and
 * verification tooling reports bindings that use it as non-production
 * findings.
 *
 * The pluggability micro-proof is the point: a second adapter plugin
 * landed with one new class and no edit to the manager, resolver,
 * validator, or factory.
 */
#[Group('flysystem')]
#[CoversNothing]
final class MemoryAdapterPluginTest extends TestCase {

  /**
   * Per-test temp directory.
   *
   * @var string
   */
  private string $tempRoot = '';

  /**
   * The mock app root used by the local backend under test.
   *
   * @var string
   */
  private string $webRoot = '';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->tempRoot = sys_get_temp_dir() . '/flysystem-m110-' . getmypid();
    $this->webRoot = $this->tempRoot . '/web';
    mkdir($this->webRoot, 0777, TRUE);
    // The local adapter receives the app root through the container; a mock
    // stands in for that single parameter, as in the factory tests.
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
   * Verifies the memory plugin satisfies the adapter contract.
   */
  public function testMemoryPluginSatisfiesTheAdapterContract(): void {
    $plugin = new Memory([], 'memory', []);
    self::assertInstanceOf(FlysystemAdapterInterface::class, $plugin);
    self::assertTrue($plugin->isAvailable(), 'The dev package is present in this environment; the plugin must say so honestly.');
    self::assertFalse($plugin instanceof LocalStorageInterface, 'Memory declares no local storage: no webroot containment, no disk semantics.');
  }

  /**
   * Verifies building the memory operator touches no filesystem.
   */
  public function testMemoryBuildCreatesNoDiskArtifacts(): void {
    $plugin = new Memory([], 'memory', []);
    $operator = $plugin->buildFilesystem(['root' => $this->tempRoot . '/never-created']);
    $operator->write('only/here.txt', 'ram');
    self::assertSame('ram', $operator->read('only/here.txt'));
    self::assertDirectoryDoesNotExist($this->tempRoot . '/never-created', 'The configured root is ignored by design: memory stores in RAM and never creates paths.');
  }

  /**
   * Proves round-trip parity through the same factory pipeline.
   *
   * Identical harness assertions must pass against both shipped backends.
   */
  #[DataProvider('shippedPlugins')]
  public function testBothBackendsRoundTripThroughTheSamePipeline(string $pluginId): void {
    $factory = $this->factory($pluginId);
    $operator = $factory->operatorFor('public');
    self::assertInstanceOf(FilesystemOperator::class, $operator);
    $operator->write('a/b.txt', 'parity');
    self::assertSame('parity', $operator->read('a/b.txt'));
    $paths = [];
    foreach ($operator->listContents('', TRUE) as $item) {
      if ($item->type() === 'file') {
        $paths[] = $item->path();
      }
    }
    self::assertSame(['a/b.txt'], $paths);
  }

  /**
   * Proves memoization holds per backend.
   */
  #[DataProvider('shippedPlugins')]
  public function testBothBackendsMemoizeIdentically(string $pluginId): void {
    $factory = $this->factory($pluginId);
    self::assertSame($factory->operatorFor('public'), $factory->operatorFor('public'), 'Per-request identity holds for both backends.');
  }

  /**
   * Two backends on otherwise-identical config are distinct identities.
   */
  public function testPluginIdentitySeparatesOperators(): void {
    $factory = $this->managerFactory([
      'adapters' => [
        'a' => ['plugin' => 'local', 'config' => ['root' => 'shared-name']],
        'b' => ['plugin' => 'memory', 'config' => ['root' => 'shared-name']],
      ],
      'bindings' => [
        'first' => ['adapter' => 'a', 'publicly_accessible' => FALSE],
        'second' => ['adapter' => 'b'],
      ],
    ]);
    self::assertNotSame($factory->operatorFor('first'), $factory->operatorFor('second'), 'The plugin id is part of the definition identity; identical config must not collapse two backends into one operator.');
  }

  /**
   * Verifies memory runs under the strict traversal posture.
   *
   * It declares no local storage, so strictness applies like any
   * non-local backend.
   */
  public function testMemoryRunsUnderStrictPosture(): void {
    $factory = $this->factory('memory');
    $this->expectException(PathTraversalDetected::class);
    $factory->operatorFor('public')->read('a/../b.txt');
  }

  /**
   * Verifies a leading escape throws for memory too.
   */
  public function testMemoryRejectsLeadingEscape(): void {
    $factory = $this->factory('memory');
    $this->expectException(PathTraversalDetected::class);
    $factory->operatorFor('public')->write('../escape.txt', 'no');
  }

  /**
   * Verifies the memory plugin's presence cannot affect other bindings.
   *
   * Discovery may or may not report it; resolution for a local binding
   * is identical either way.
   */
  public function testMemoryPresenceNeverAffectsOtherSchemes(): void {
    $layer = [
      'adapters' => [
        'main' => ['plugin' => 'local', 'config' => ['root' => 'files']],
      ],
      'bindings' => [
        'public' => ['adapter' => 'main'],
      ],
    ];
    $withMemory = $this->managerFactory($layer, includeMemory: TRUE)->operatorFor('public');
    $withoutMemory = $this->managerFactory($layer, includeMemory: FALSE)->operatorFor('public');
    $withMemory->write('same.txt', 'both');
    self::assertSame('both', $withoutMemory->read('same.txt'), 'Both managers resolve the same definition; the optional plugin changes nothing for schemes that do not use it.');
  }

  /**
   * Provides the two shipped adapter plugin ids.
   *
   * @return array<string, array{0: string}>
   *   Plugin ids.
   */
  public static function shippedPlugins(): array {
    return [
      'local' => ['local'],
      'memory' => ['memory'],
    ];
  }

  /**
   * Builds a factory bound to the public scheme on one plugin.
   *
   * The root 'store' is meaningful only to the local backend; memory
   * ignores it by design.
   */
  private function factory(string $pluginId): OperatorFactory {
    return $this->managerFactory([
      'adapters' => [
        'main' => ['plugin' => $pluginId, 'config' => ['root' => 'store']],
      ],
      'bindings' => [
        'public' => ['adapter' => 'main'],
      ],
    ]);
  }

  /**
   * Assembles the full pipeline over an injected manager discovery.
   *
   * @param array $layer
   *   The two-section layer.
   * @param bool $includeMemory
   *   Whether discovery should report the memory plugin.
   *
   * @return \Drupal\flysystem\Factory\OperatorFactory
   *   The wired factory.
   */
  private function managerFactory(array $layer, bool $includeMemory = TRUE): OperatorFactory {
    $manager = $this->manager($includeMemory);
    $isLocal = static function (string $pluginId) use ($manager): bool {
      $definition = $manager->getDefinition($pluginId, FALSE);
      if ($definition === NULL) {
        throw new UnknownAdapterPluginException($pluginId, 'presence-check');
      }
      return is_subclass_of($definition['class'], LocalStorageInterface::class);
    };
    $validator = new SettingsValidator($this->webRoot, $isLocal);
    $resolver = new BindingResolver([new ArrayDefinitionSource($layer)], $validator);
    return new OperatorFactory($resolver, $manager, $isLocal);
  }

  /**
   * A manager whose discovery reports the shipped plugins.
   *
   * @param bool $includeMemory
   *   FALSE simulates an environment where the memory plugin is absent.
   */
  private function manager(bool $includeMemory): FlysystemAdapterManager {
    $definitions = [
      'local' => ['id' => 'local', 'class' => Local::class],
    ];
    if ($includeMemory) {
      $definitions['memory'] = ['id' => 'memory', 'class' => Memory::class];
    }
    $manager = new FlysystemAdapterManager(
      new \ArrayObject(),
      $this->createMock(CacheBackendInterface::class),
      $this->createMock(ModuleHandlerInterface::class),
    );
    $discovery = new class ($definitions) implements DiscoveryInterface {

      public function __construct(private array $definitions) {
      }

      /**
       * Returns the fixture definitions.
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
       *   TRUE when defined.
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

}
