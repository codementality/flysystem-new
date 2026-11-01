<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Unit\Compiler;

use Drupal\flysystem\Adapter\FlysystemAdapterManager;
use Drupal\flysystem\Compiler\RegisterFlysystemAdaptersPass;
use Drupal\flysystem\Exception\FlysystemAdapterTagException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Tests the tag-to-definition merge that makes adapters pluggable.
 *
 * Adapter services are contributed the way core contributes its own
 * tagged services: a module declares the service and adds
 * <code>{ name: flysystem.adapter, driver: ... }</code> — four lines,
 * no annotation classes required. A compiler pass collects every tagged
 * service at container build time and hands the manager the definition
 * list. Mistakes in a contributed tag (missing driver, a driver two
 * services both claim, a spelling the adapter-id grammar cannot accept)
 * are developer wiring errors, so they fail loudly at build with typed
 * errors naming the offending service.
 */
#[Group('flysystem')]
#[CoversNothing]
final class RegisterFlysystemAdaptersPassTest extends TestCase {

  /**
   * Builds a container with the manager and one tagged adapter service.
   */
  private function container(array $tags_by_service): ContainerBuilder {
    $container = new ContainerBuilder();
    $container
      ->register('flysystem.adapter_manager', FlysystemAdapterManager::class)
      ->setArguments([[]]);
    foreach ($tags_by_service as $service_id => $tags) {
      $definition = new Definition('Drupal\\any\\AdapterClass');
      foreach ($tags as $attributes) {
        $definition->addTag('flysystem.adapter', $attributes);
      }
      $container->setDefinition($service_id, $definition);
    }
    return $container;
  }

  /**
   * Verifies tagged services become driver-keyed definitions.
   */
  public function testTaggedServicesBecomeDefinitions(): void {
    $container = $this->container([
      'flysystem.adapter.local' => [
        ['driver' => 'local', 'label' => 'Local files'],
      ],
      'my_module.adapter.remote' => [
        ['driver' => 'test_remote', 'label' => 'Test remote'],
      ],
    ]);

    (new RegisterFlysystemAdaptersPass())->process($container);

    $definitions = $container
      ->getDefinition('flysystem.adapter_manager')
      ->getArgument(0);
    self::assertSame([
      'local' => [
        'driver' => 'local',
        'label' => 'Local files',
        'service' => 'flysystem.adapter.local',
      ],
      'test_remote' => [
        'driver' => 'test_remote',
        'label' => 'Test remote',
        'service' => 'my_module.adapter.remote',
      ],
    ], $definitions, 'Every tagged service contributes one definition keyed by driver, sorted, carrying its service id and label.');
  }

  /**
   * Verifies multiple tags on one service each become a definition.
   */
  public function testMultipleTagsOnOneServiceEachBecomeADefinition(): void {
    $container = $this->container([
      'my_module.adapter.dual' => [
        ['driver' => 'first', 'label' => 'First'],
        ['driver' => 'second', 'label' => 'Second'],
      ],
    ]);

    (new RegisterFlysystemAdaptersPass())->process($container);

    $definitions = $container
      ->getDefinition('flysystem.adapter_manager')
      ->getArgument(0);
    self::assertSame(['first', 'second'], array_keys($definitions), 'One class may serve two drivers, each a separate definition pointing at the same service.');
  }

  /**
   * Verifies a tag without a driver is a typed error naming the service.
   */
  public function testTagMissingDriverThrows(): void {
    $container = $this->container([
      'my_module.adapter.nameless' => [['label' => 'No driver here']],
    ]);

    $this->expectException(FlysystemAdapterTagException::class);
    $this->expectExceptionMessageMatches('/my_module\.adapter\.nameless/');
    (new RegisterFlysystemAdaptersPass())->process($container);
  }

  /**
   * Verifies two services claiming one driver is a typed error.
   */
  public function testDuplicateDriverThrows(): void {
    $container = $this->container([
      'a.adapter' => [['driver' => 'dupe']],
      'b.adapter' => [['driver' => 'dupe']],
    ]);

    $this->expectException(FlysystemAdapterTagException::class);
    $this->expectExceptionMessageMatches('/dupe/');
    (new RegisterFlysystemAdaptersPass())->process($container);
  }

  /**
   * Verifies a hyphenated driver is rejected and the canonical form named.
   */
  public function testHyphenatedDriverRejectedWithCanonicalHint(): void {
    $container = $this->container([
      'my_module.adapter.aws' => [['driver' => 'aws-s3']],
    ]);

    $this->expectException(FlysystemAdapterTagException::class);
    $this->expectExceptionMessageMatches('/aws_s3/');
    (new RegisterFlysystemAdaptersPass())->process($container);
  }

  /**
   * Verifies the pass is inert when no manager exists to feed.
   */
  public function testPassSkipsWhenManagerServiceAbsent(): void {
    $container = new ContainerBuilder();
    $container->setDefinition('a', (new Definition('X'))
      ->addTag('flysystem.adapter', ['driver' => 'local']));

    (new RegisterFlysystemAdaptersPass())->process($container);

    self::assertFalse($container->has('flysystem.adapter_manager'), 'Without the manager there is nothing to register into; the pass must not explode in that container.');
  }

}
