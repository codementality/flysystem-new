<?php

declare(strict_types=1);

namespace Drupal\flysystem\Tests\Unit\Factory;

use Psr\Log\LoggerInterface;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Component\Plugin\Discovery\DiscoveryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\flysystem\Definition\ArrayDefinitionSource;
use Drupal\flysystem\Exception\FlysystemPackageMissingException;
use Drupal\flysystem\Exception\UnknownAdapterPluginException;
use Drupal\flysystem\Factory\OperatorFactory;
use Drupal\flysystem\Plugin\FlysystemAdapter\Local;
use Drupal\flysystem\Plugin\FlysystemAdapterBase;
use Drupal\flysystem\Plugin\FlysystemAdapterInterface;
use Drupal\flysystem\Plugin\FlysystemAdapterManager;
use Drupal\flysystem\Plugin\LocalStorageInterface;
use Drupal\flysystem\Resolution\BindingResolver;
use Drupal\flysystem\Validation\SettingsValidator;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use League\Flysystem\PathTraversalDetected;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Tests for the operator factory: resolve, posture, build, memoize.
 *
 * The factory is where a scheme string becomes a working FilesystemOperator
 * — or nothing at all for core-native schemes. The end-to-end outcome (write,
 * read, list a real temp directory through a resolved public-style binding)
 * is the point of the whole foundation, so it is asserted here literally.
 *
 * Posture rule: local-storage bindings get the library's default traversal
 * behavior (interior '..' resolves inside the bound root); remote-style
 * bindings are built strict (interior '..' is rejected outright). Leading
 * root-escape throws in both postures — that guarantee is library-invariant.
 * The posture travels in the merged configuration under a reserved key that
 * plugins forward to the operator; it keys off the local-storage feature,
 * never off plugin names.
 *
 * Memoization is by definition identity: repeated or distinct requests for
 * equivalent configurations return the very same instance; a genuinely
 * different configuration builds a second one. Nothing is resolved or built
 * until a scheme is actually asked for.
 */
#[Group('flysystem')]
#[CoversNothing]
final class OperatorFactoryTest extends TestCase {

  /**
   * Per-test temp directory.
   *
   * @var string
   */
  private string $tempRoot = '';

  /**
   * The mock app root the local plugin is anchored against.
   *
   * @var string
   */
  private string $webRoot = '';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->tempRoot = sys_get_temp_dir() . '/flysystem-m108-' . getmypid();
    $this->webRoot = $this->tempRoot . '/web';
    mkdir($this->webRoot, 0777, TRUE);
    // The local adapter receives the app root through the container, exactly
    // as production wires it; a mock stands in for that one parameter.
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
    // Leave no test-specific container behind for sibling tests.
    \Drupal::setContainer($this->createMock(ContainerInterface::class));
    exec('rm -rf ' . escapeshellarg($this->tempRoot));
    parent::tearDown();
  }

  /**
   * Assembles the production shape: resolver + manager + feature predicate.
   *
   * @param array $settings_layer
   *   The layered settings shape under test.
   *
   * @return \Drupal\flysystem\Factory\OperatorFactory
   *   A factory wired exactly as the container will wire it.
   */
  private function factory(array $settings_layer): OperatorFactory {
    $manager = $this->manager();
    $isLocal = static function (string $pluginId) use ($manager): bool {
      $definition = $manager->getDefinition($pluginId, FALSE);
      if ($definition === NULL) {
        throw new UnknownAdapterPluginException($pluginId, 'unknown-plugin-check');
      }
      return is_subclass_of($definition['class'], LocalStorageInterface::class);
    };
    $validator = new SettingsValidator($this->webRoot, $isLocal);
    $resolver = new BindingResolver([new ArrayDefinitionSource($settings_layer)], $validator);
    return new OperatorFactory($resolver, $manager, $isLocal, $this->createMock(LoggerInterface::class));
  }

  /**
   * Builds the adapter layer for a local plugin rooted at $root.
   *
   * @param string $plugin
   *   The plugin id to bind.
   * @param string|array $root
   *   The configured root.
   *
   * @return array
   *   An adapter definition layer entry.
   */
  private function localAdapters(string $plugin, string|array $root): array {
    return [
      'plugin' => $plugin,
      'config' => ['root' => $root],
    ];
  }

  /**
   * A manager whose discovery reports the real local plugin and fixtures.
   */
  private function manager(): FlysystemAdapterManager {
    $definitions = [
      'local' => [
        'id' => 'local',
        'class' => Local::class,
      ],
      'remote_fixture' => [
        'id' => 'remote_fixture',
        'class' => RemotePostureFixture::class,
      ],
      'unavailable_fixture' => [
        'id' => 'unavailable_fixture',
        'class' => UnavailableFixture::class,
      ],
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
       * Returns the fixture definitions.
       *
       * @return array<string, array<string, string>>
       *   Definitions keyed by plugin id.
       */
      public function getDefinitions() {
        return $this->definitions;
      }

      /**
       * Returns one fixture definition by id.
       *
       * @param string $plugin_id
       *   The plugin id.
       * @param bool $return_empty
       *   TRUE to return NULL for unknown ids instead of throwing.
       *
       * @return array<string, string>|null
       *   The definition, or NULL when allowed.
       */
      public function getDefinition($plugin_id, $return_empty = FALSE) {
        if (isset($this->definitions[$plugin_id])) {
          return $this->definitions[$plugin_id];
        }
        if ($return_empty) {
          return NULL;
        }
        throw new PluginNotFoundException($plugin_id);
      }

      /**
       * Whether the fixture holds a definition.
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

  /**
   * The end-to-end outcome: a resolved public-style binding really works.
   */
  public function testResolvedPublicStyleBindingWritesReadsAndLists(): void {
    $factory = $this->factory([
      'adapters' => [
        'main' => $this->localAdapters('local', 'store'),
      ],
      'bindings' => [
        'public' => ['adapter' => 'main'],
      ],
    ]);

    $operator = $factory->operatorFor('public');
    self::assertInstanceOf(FilesystemOperator::class, $operator);
    $operator->write('notes/readme.txt', 'factory built this');
    self::assertSame('factory built this', $operator->read('notes/readme.txt'));
    $paths = [];
    foreach ($operator->listContents('', TRUE) as $item) {
      if ($item->type() === 'file') {
        $paths[] = $item->path();
      }
    }
    self::assertSame(['notes/readme.txt'], $paths);
    self::assertFileExists(
      $this->webRoot . '/store/notes/readme.txt',
      "The operator must land in the configured root under the app root, materialized on first write.",
    );
  }

  /**
   * Verifies an unbound scheme yields no operator and builds no plugin.
   */
  public function testUnboundSchemeResolvesToNoOperator(): void {
    $factory = $this->factory(['adapters' => [], 'bindings' => []]);
    self::assertNull($factory->operatorFor('nothing_here'));
  }

  /**
   * Verifies a broken neighbor scheme does not throw until touched.
   */
  public function testLazyUntilTouched(): void {
    $factory = $this->factory([
      'adapters' => [
        'main' => $this->localAdapters('local', 'ok'),
        'bad' => ['plugin' => 'no_such_package'],
      ],
      'bindings' => [
        'public' => ['adapter' => 'main'],
        'broken' => ['adapter' => 'bad'],
      ],
    ]);
    self::assertInstanceOf(FilesystemOperator::class, $factory->operatorFor('public'));
    $this->expectException(UnknownAdapterPluginException::class);
    $factory->operatorFor('broken');
  }

  /**
   * Verifies repeated requests return the identical instance.
   */
  public function testSameDefinitionReturnsIdenticalInstance(): void {
    $factory = $this->factory([
      'adapters' => [
        'main' => $this->localAdapters('local', 'memo'),
      ],
      'bindings' => [
        'public' => ['adapter' => 'main'],
        'second' => ['adapter' => 'main'],
      ],
    ]);
    $first = $factory->operatorFor('public');
    $again = $factory->operatorFor('public');
    $other = $factory->operatorFor('second');
    self::assertSame($first, $again, 'Per-request memoization must hand back the same object.');
    self::assertSame(
      $first,
      $other,
      'Two schemes on one definition share one operator — the key is the definition, not the scheme.',
    );
  }

  /**
   * Verifies different definitions genuinely build different instances.
   */
  public function testDifferentDefinitionsBuildSeparateInstances(): void {
    $factory = $this->factory([
      'adapters' => [
        'main' => $this->localAdapters('local', 'a'),
        'alt' => $this->localAdapters('local', 'b'),
      ],
      'bindings' => [
        'public' => ['adapter' => 'main'],
        'other' => ['adapter' => 'alt'],
      ],
    ]);
    self::assertNotSame($factory->operatorFor('public'), $factory->operatorFor('other'));
  }

  /**
   * Verifies the local posture: interior '..' resolves inside the bound root.
   */
  public function testLocalPostureResolvesInteriorTraversal(): void {
    $factory = $this->factory([
      'adapters' => [
        'main' => $this->localAdapters('local', 'posture'),
      ],
      'bindings' => [
        'public' => ['adapter' => 'main'],
      ],
    ]);
    $operator = $factory->operatorFor('public');
    $operator->write('deep/target.txt', 'v');
    self::assertSame(
      'v',
      $operator->read('deep/../deep/target.txt'),
      'Local-mode operators keep the library default: interior .. collapses within the bound root.',
    );
  }

  /**
   * Verifies the remote posture: interior '..' is rejected outright.
   */
  public function testRemotePostureRejectsInteriorTraversal(): void {
    $operator = $this->remoteOperator();
    $this->expectException(PathTraversalDetected::class);
    $operator->read('data/../other.txt');
  }

  /**
   * Verifies the remote posture arrives through the real build path.
   */
  public function testRemotePostureAppliedByFactory(): void {
    $factory = $this->factory([
      'adapters' => [
        'cdn' => [
          'plugin' => 'remote_fixture',
          'config' => ['bucket' => 'anything'],
        ],
      ],
      'bindings' => [
        'cdn' => ['adapter' => 'cdn', 'publicly_accessible' => TRUE],
      ],
    ]);
    $operator = $factory->operatorFor('cdn');
    self::assertInstanceOf(FilesystemOperator::class, $operator);
    $this->expectException(PathTraversalDetected::class);
    $operator->read('data/../other.txt');
  }

  /**
   * Verifies a leading escape throws under the local posture.
   */
  public function testLeadingEscapeThrowsLocally(): void {
    $factory = $this->factory([
      'adapters' => [
        'main' => $this->localAdapters('local', 'escape'),
      ],
      'bindings' => ['public' => ['adapter' => 'main']],
    ]);
    $this->expectException(PathTraversalDetected::class);
    $factory->operatorFor('public')->write('../outside.txt', 'no');
  }

  /**
   * Verifies a leading escape throws for the remote posture too.
   */
  public function testLeadingEscapeThrowsForRemote(): void {
    $this->expectException(PathTraversalDetected::class);
    $this->remoteOperator()->write('../outside.txt', 'no');
  }

  /**
   * Verifies a missing backend surfaces the typed package exception.
   */
  public function testMissingPackageThrowsTypedExceptionNamingThePlugin(): void {
    $factory = $this->factory([
      'adapters' => [
        'needs_pkg' => ['plugin' => 'unavailable_fixture', 'config' => []],
      ],
      'bindings' => ['store' => ['adapter' => 'needs_pkg']],
    ]);
    $this->expectException(FlysystemPackageMissingException::class);
    $this->expectExceptionMessageMatches('/unavailable_fixture/');
    $factory->operatorFor('store');
  }

  /**
   * Builds a remote-posture operator the way the factory merges it.
   */
  private function remoteOperator(): FilesystemOperator {
    $plugin = new RemotePostureFixture([], 'remote_fixture', []);
    return $plugin->buildFilesystem(['allow_relative_path_traversal' => FALSE]);
  }

}

/**
 * A remote-style fixture backend honoring the posture merge key.
 *
 * Stands in for the object-store adapters later milestones will ship as
 * plugins: in-memory storage (no filesystem semantics), strict traversal.
 */
final class RemotePostureFixture extends FlysystemAdapterBase implements FlysystemAdapterInterface {

  /**
   * {@inheritdoc}
   */
  public function buildFilesystem(array $config = []): FilesystemOperator {
    $config += $this->getConfiguration();
    return new Filesystem(
      new InMemoryFilesystemAdapter(),
      ['allow_relative_path_traversal' => $config['allow_relative_path_traversal'] ?? TRUE],
    );
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return TRUE;
  }

}

/**
 * A fixture backend whose underlying package is not installed.
 */
final class UnavailableFixture extends FlysystemAdapterBase implements FlysystemAdapterInterface {

  /**
   * {@inheritdoc}
   */
  public function buildFilesystem(array $config = []): FilesystemOperator {
    throw new \LogicException('Unreachable in tests: the availability gate must stop first.');
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return FALSE;
  }

}
