<?php

declare(strict_types=1);

namespace Drupal\flysystem\Tests\Unit\Resolution;

use Drupal\flysystem\Definition\ArrayDefinitionSource;
use Drupal\flysystem\Exception\PublicRootOutsideWebrootException;
use Drupal\flysystem\Exception\UnknownAdapterDefinitionException;
use Drupal\flysystem\Exception\UnknownAdapterPluginException;
use Drupal\flysystem\Resolution\BindingResolver;
use Drupal\flysystem\Validation\SettingsValidator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests the binding resolver and its precedence algorithm.
 *
 * Each resolution rule is expressed one per data set: adapter definitions
 * deep-merge per leaf with the higher-precedence source winning; a
 * settings binding replaces the same-scheme entity binding whole (it
 * does not merge); a binding marked disabled => TRUE resolves the scheme
 * to core-native (NULL); an unbound scheme resolves NULL; settings-only
 * schemes resolve normally. The behavior letting an entity binding
 * replace a core scheme unless settings pins that scheme falls out of
 * the same precedence walk, and is tested explicitly.
 *
 * Resolution composes with the settings validator at the touched-scheme
 * moment: the resolver asks the validator about the effective merged
 * layer, so validation errors surface only when a scheme is actually
 * resolved. The validator's local-versus-remote question is answered by
 * the callable its caller injects; these tests use a plain plugin-id map
 * so the resolver is tested without depending on any adapter plugin.
 *
 * Sources are consumed strictly in the order given (highest precedence
 * first) and only through the definition-source interface — so any
 * source, including future config-entity storage, plugs in without
 * changing the resolver.
 */
#[Group('flysystem')]
#[CoversNothing]
final class BindingResolverPrecedenceTest extends TestCase {

  /**
   * Builds a resolver with the given layered sources and validation wiring.
   *
   * The locality callable stands in for the production answer: 'local' is
   * a local-storage plugin, 's3' is remote, anything else is unknown —
   * the three answers the validator consumes.
   *
   * @param array $settings_layer
   *   The settings source layer.
   * @param array $entity_layer
   *   The entity source layer (supplied via the array-backed source).
   *
   * @return \Drupal\flysystem\Resolution\BindingResolver
   *   Resolver wired the way production will wire it, with fakes.
   */
  private function resolver(array $settings_layer, array $entity_layer): BindingResolver {
    $is_local = static fn (string $plugin_id): ?bool => match ($plugin_id) {
      'local' => TRUE,
      's3' => FALSE,
      default => NULL,
    };
    $validator = new SettingsValidator('/app-root', $is_local);
    $sources = [
      new ArrayDefinitionSource($settings_layer),
      new ArrayDefinitionSource($entity_layer),
    ];
    return new BindingResolver($sources, $validator);
  }

  /**
   * Provides the full precedence matrix.
   */
  #[DataProvider('precedenceProvider')]
  public function testResolveAnswersThePrecedenceMatrix(array $settings, array $entity, string $scheme, ?array $expected): void {
    $result = $this->resolver($settings, $entity)->resolve($scheme);
    self::assertEquals($expected, $result);
  }

  /**
   * Provides the precedence matrix.
   *
   * @return array<string, array{0: array, 1: array, 2: string, 3: array|null}>
   *   Named cases: settings layer, entity layer, scheme, expected answer.
   */
  public static function precedenceProvider(): array {
    $local_def = [
      'plugin' => 'local',
      'config' => ['root' => 'sites/default/files'],
    ];
    $settings_main = [
      'adapters' => ['main' => $local_def],
      'bindings' => ['public' => ['adapter' => 'main']],
    ];
    return [
      'unbound scheme resolves NULL' => [
        [],
        [],
        'public',
        NULL,
      ],
      'entity-only binding resolves the entity definition' => [
        [],
        $settings_main,
        'public',
        [
          'plugin' => 'local',
          'config' => ['root' => 'sites/default/files'],
          'publicly_accessible' => TRUE,
        ],
      ],
      'settings-only scheme is valid' => [
        [
          'adapters' => ['main' => $local_def],
          'bindings' => ['backup' => ['adapter' => 'main']],
        ],
        [],
        'backup',
        [
          'plugin' => 'local',
          'config' => ['root' => 'sites/default/files'],
          'publicly_accessible' => FALSE,
        ],
      ],
      'settings binding replaces same-scheme entity binding' => [
        [
          'adapters' => [
            'main' => [
              'plugin' => 'local',
              'config' => ['root' => 'settings-root'],
            ],
          ],
          'bindings' => ['public' => ['adapter' => 'main']],
        ],
        [
          'adapters' => [
            'other' => [
              'plugin' => 'local',
              'config' => ['root' => 'entity-root'],
            ],
          ],
          'bindings' => ['public' => ['adapter' => 'other']],
        ],
        'public',
        [
          'plugin' => 'local',
          'config' => ['root' => 'settings-root'],
          'publicly_accessible' => TRUE,
        ],
      ],
      'adapter definitions deep-merge per leaf, settings wins' => [
        [
          'adapters' => [
            'main' => [
              'plugin' => 'local',
              'config' => ['root' => 'from-settings', 'keep' => 'settings'],
            ],
          ],
          'bindings' => ['public' => ['adapter' => 'main']],
        ],
        [
          'adapters' => [
            'main' => [
              'plugin' => 'local',
              'config' => ['root' => 'from-entity', 'entity_only' => TRUE],
            ],
          ],
          'bindings' => [],
        ],
        'public',
        [
          'plugin' => 'local',
          'config' => [
            'root' => 'from-settings',
            'entity_only' => TRUE,
            'keep' => 'settings',
          ],
          'publicly_accessible' => TRUE,
        ],
      ],
      'binding config overrides adapter config per leaf' => [
        [
          'adapters' => [
            'main' => [
              'plugin' => 'local',
              'config' => ['root' => 'base', 'lazy' => TRUE],
            ],
          ],
          'bindings' => [
            'public' => [
              'adapter' => 'main',
              'config' => ['root' => 'override'],
            ],
          ],
        ],
        [],
        'public',
        [
          'plugin' => 'local',
          'config' => ['root' => 'override', 'lazy' => TRUE],
          'publicly_accessible' => TRUE,
        ],
      ],
      'disabled settings binding resolves core-native over entity binding' => [
        [
          'adapters' => ['main' => $local_def],
          'bindings' => [
            'public' => ['adapter' => 'main', 'disabled' => TRUE],
          ],
        ],
        $settings_main,
        'public',
        NULL,
      ],
      'disabled entity binding resolves core-native with no settings binding' => [
        [],
        [
          'adapters' => ['main' => $local_def],
          'bindings' => [
            'private' => ['adapter' => 'main', 'disabled' => TRUE],
          ],
        ],
        'private',
        NULL,
      ],
      'entity binding replaces a core scheme when settings does not pin it' => [
        [],
        [
          'adapters' => [
            'cdn' => ['plugin' => 's3', 'config' => ['bucket' => 'x']],
          ],
          'bindings' => ['public' => ['adapter' => 'cdn']],
        ],
        'public',
        [
          'plugin' => 's3',
          'config' => ['bucket' => 'x'],
          'publicly_accessible' => TRUE,
        ],
      ],
    ];
  }

  /**
   * Verifies a binding referencing an undefined adapter throws typed.
   */
  public function testBindingToUndefinedAdapterThrowsTypedException(): void {
    $this->expectException(UnknownAdapterDefinitionException::class);
    $this->expectExceptionMessageMatches('/ghost/');
    $this->resolver([], ['adapters' => [], 'bindings' => ['public' => ['adapter' => 'ghost']]])->resolve('public');
  }

  /**
   * Verifies validation happens for the touched scheme only.
   */
  public function testValidSchemeResolvesWhileBrokenOtherSchemeStillThrowsOnlyOnTouch(): void {
    $settings = [
      'adapters' => [
        'main' => ['plugin' => 'local', 'config' => ['root' => 'inside']],
        'bad' => ['plugin' => 'local', 'config' => ['root' => '/srv/outside']],
      ],
      'bindings' => [
        'public' => ['adapter' => 'main'],
        'private' => ['adapter' => 'bad', 'publicly_accessible' => TRUE],
      ],
    ];
    $resolver = $this->resolver($settings, []);
    $resolved = $resolver->resolve('public');
    self::assertNotNull($resolved, 'The healthy scheme must resolve untouched by the neighboring defect.');
    $this->expectException(PublicRootOutsideWebrootException::class);
    $resolver->resolve('private');
  }

  /**
   * Verifies the plugin-locality wiring surfaces unknown plugins.
   */
  public function testUnknownPluginFailsAtResolutionOfTheTouchedScheme(): void {
    $this->expectException(UnknownAdapterPluginException::class);
    $layer = [
      'adapters' => ['main' => ['plugin' => 'no_such_pkg']],
      'bindings' => ['public' => ['adapter' => 'main']],
    ];
    $this->resolver($layer, [])->resolve('public');
  }

  /**
   * Verifies remote backends are exempt from webroot containment.
   *
   * Webroot containment applies to local-storage adapters only; a remote
   * backend has no webroot concept, and "serve public files from outside
   * the docroot" is legitimately expressed as a remote binding — the
   * module's primary remote-storage use case. The validator's own tests
   * cover the rule; this test proves the resolver feeds the validator
   * the correct local-versus-remote answer for the plugin a binding
   * references. Without that wiring, a wrongly local answer would pass
   * the neighboring rule test while rejecting every remote public
   * binding at resolve time — broken in production, green in the suite.
   */
  public function testRemoteBindingResolvesPubliclyWithExternalConfig(): void {
    $settings = [
      'adapters' => ['cdn' => ['plugin' => 's3', 'config' => ['bucket' => 'outside-any-webroot']]],
      'bindings' => ['public' => ['adapter' => 'cdn']],
    ];
    $resolved = $this->resolver($settings, [])->resolve('public');
    self::assertSame('s3', $resolved['plugin'], 'The exemption follows the plugin-locality answer, not name guessing.');
  }

}
