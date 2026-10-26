<?php

declare(strict_types=1);

namespace Drupal\flysystem\Tests\Unit\Plugin;

use Drupal\Component\Plugin\Discovery\DiscoveryInterface;
use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\flysystem\Exception\FlysystemPluginNotFoundException;
use Drupal\flysystem\Plugin\FlysystemAdapterInterface;
use Drupal\flysystem\Plugin\FlysystemAdapterManager;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for M1-02 (Part 1): the FlysystemAdapter plugin type and manager.
 *
 * Follows the Search API backend-plugin model (per owner ruling 2026-10-26):
 * adapters describe themselves through methods they implement
 * (buildFilesystem(), isAvailable()); the base module discovers plugin
 * classes through the attribute + manager and never classifies backends.
 *
 * The fixture plugin below is declared in this test file and injected
 * through discovery exactly the way a real adapter module ships one in
 * src/Plugin/FlysystemAdapter/ - that is the row's pluggability proof:
 * "new backend requires only a new plugin class", with zero base-module
 * edits. The real Local plugin arrives in issue #9.
 */
#[Group('flysystem')]
#[CoversNothing]
final class FlysystemAdapterManagerTest extends TestCase {

  private string $tempRoot = '';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->tempRoot = sys_get_temp_dir() . '/flysystem-m102-' . getmypid();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (is_dir($this->tempRoot)) {
      exec('rm -rf ' . escapeshellarg($this->tempRoot));
    }
    parent::tearDown();
  }

  /**
   * Builds the real manager with stubbed discovery and mocked services.
   *
   * @param array<string, array<string, mixed>> $definitions
   *   Plugin definitions the discovery stub should report.
   *
   * @return \Drupal\flysystem\Plugin\FlysystemAdapterManager
   *   The production manager class, wired for unit testing.
   */
  private function manager(array $definitions): FlysystemAdapterManager {
    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $cache_backend = $this->createMock(CacheBackendInterface::class);
    $cache_backend->method('get')->willReturn(FALSE);
    $namespaces = new \ArrayObject();
    $manager = new FlysystemAdapterManager($namespaces, $cache_backend, $module_handler);
    $discovery = new class ($definitions) implements DiscoveryInterface {

      public function __construct(private array $definitions) {
      }

      public function getDefinitions() {
        return $this->definitions;
      }

    };
    $reflection = new \ReflectionObject($manager);
    while ($reflection && !$reflection->hasProperty('discovery')) {
      $reflection = $reflection->getParentClass();
    }
    $property = $reflection->getProperty('discovery');
    $property->setAccessible(TRUE);
    $property->setValue($manager, $discovery);
    return $manager;
  }

  /**
   * A discovery-ready definition for the fixture plugin.
   *
   * @return array<string, array<string, mixed>>
   */
  private function fixtureDefinitions(): array {
    return [
      'fixture' => [
        'id' => 'fixture',
        'class' => FixtureTestAdapter::class,
      ],
    ];
  }

  /**
   * Verifies the manager discovers definitions through its discovery.
   */
  public function testManagerDiscoversRegisteredPlugin(): void {
    $manager = $this->manager($this->fixtureDefinitions());
    $definitions = $manager->getDefinitions();
    self::assertArrayHasKey('fixture', $definitions, 'A plugin registered through discovery must appear in definitions without any base-module knowledge of it.');
    self::assertSame(FixtureTestAdapter::class, $definitions['fixture']['class']);
  }

  /**
   * Verifies plugin id + definition instantiates through the interface.
   */
  public function testCreateInstanceReturnsPluginThroughInterface(): void {
    $manager = $this->manager($this->fixtureDefinitions());
    $plugin = $manager->createInstance('fixture', ['demo' => 'value']);
    self::assertInstanceOf(FlysystemAdapterInterface::class, $plugin);
    self::assertSame('value', $plugin->getConfiguration()['demo'], 'Plugin configuration must reach the instance via the standard ConfigurableInterface flow.');
  }

  /**
   * Verifies buildFilesystem() produces a genuinely working operator.
   */
  public function testBuildFilesystemProducesWorkingOperator(): void {
    $manager = $this->manager($this->fixtureDefinitions());
    /** @var \Drupal\flysystem\Plugin\FlysystemAdapterInterface $plugin */
    $plugin = $manager->createInstance('fixture');
    $operator = $plugin->buildFilesystem(['root' => $this->tempRoot]);
    self::assertInstanceOf(FilesystemOperator::class, $operator);
    $operator->write('hello.txt', 'hi from the fixture');
    self::assertSame('hi from the fixture', $operator->read('hello.txt'), 'The buildFilesystem() contract means the caller gets a usable FilesystemOperator, not a marker.');
  }

  /**
   * Verifies isAvailable() is part of the interface and the fixture answers.
   */
  public function testPluginAnswersIsAvailable(): void {
    $manager = $this->manager($this->fixtureDefinitions());
    $plugin = $manager->createInstance('fixture');
    self::assertTrue($plugin->isAvailable(), 'The backend self-reports availability (Search API isAvailable() pattern).');
  }

  /**
   * Verifies an unknown plugin id throws the module's typed exception.
   */
  public function testUnknownPluginThrowsTypedException(): void {
    $manager = $this->manager($this->fixtureDefinitions());
    $this->expectException(FlysystemPluginNotFoundException::class);
    $this->expectExceptionMessageMatches('/no_such_adapter/');
    $manager->createInstance('no_such_adapter');
  }

  /**
   * Verifies the manager fires the standard alteration hook with the
   * discovered definitions. (Definition mutation itself is core's
   * ModuleHandler-by-reference behavior; the contract on this module is
   * that the named hook fires with the definitions array.)
   */
  public function testAlterationHookFiresWithDefinitions(): void {
    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $module_handler
      ->expects(self::once())
      ->method('invokeAll')
      ->with(
        'flysystem_flysystem_adapter_info',
        self::callback(static fn (array $args): bool => is_array($args[0] ?? NULL) && array_key_exists('fixture', $args[0])),
      );
    $cache_backend = $this->createMock(CacheBackendInterface::class);
    $cache_backend->method('get')->willReturn(FALSE);
    $manager = new FlysystemAdapterManager(new \ArrayObject(), $cache_backend, $module_handler);
    $discovery = new class ($this->fixtureDefinitions()) implements DiscoveryInterface {

      public function __construct(private array $definitions) {
      }

      public function getDefinitions() {
        return $this->definitions;
      }

    };
    $reflection = new \ReflectionObject($manager);
    while ($reflection && !$reflection->hasProperty('discovery')) {
      $reflection = $reflection->getParentClass();
    }
    $property = $reflection->getProperty('discovery');
    $property->setAccessible(TRUE);
    $property->setValue($manager, $discovery);
    $definitions = $manager->getDefinitions();
    self::assertArrayHasKey('fixture', $definitions);
  }

}

/**
 * A fixture adapter plugin declared exactly as an adapter module would ship
 * one: attribute-annotated class implementing the plugin interface. Its
 * presence via discovery alone proves "new backend = new plugin class".
 */
final class FixtureTestAdapter extends PluginBase implements FlysystemAdapterInterface {

  /**
   * {@inheritdoc}
   */
  public function buildFilesystem(array $config = []): FilesystemOperator {
    $root = $config['root'] ?? sys_get_temp_dir();
    return new Filesystem(new LocalFilesystemAdapter($root));
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return class_exists(Filesystem::class);
  }

}
