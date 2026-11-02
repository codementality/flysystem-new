<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Unit\Definition;

use Drupal\Core\Site\Settings;
use Drupal\flysystem\Definition\SettingsSource;
use Drupal\flysystem\Exception\InvalidSchemeNameException;
use Drupal\flysystem\Exception\UnknownAdapterPluginException;
use Drupal\flysystem\Resolution\BindingResolver;
use Drupal\flysystem\Validation\SettingsValidator;
use Drupal\Tests\flysystem\Traits\DeprecationCaptureTrait;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Replays genuine 2.x-format settings payloads through the modern layers.
 *
 * The upgrade promise is about the ARRAY SHAPE: the payloads below are
 * transcribed from the published 2.x module's own documentation and
 * source (the 2.2.x README CONFIGURATION example and its plugin
 * sources) — not paraphrased or invented. Keys the module does not
 * interpret (the 2.x cache/name/description family) stay inert inside
 * the adapter config; the plugin id rides through unchanged so a
 * renamed 2.x adapter spelling surfaces as an unknown-plugin error
 * that names the old word, which the upgrade documentation maps to its
 * current spelling. Scheme names keep the stricter grammar PHP registration
 * forces (2.x documented '+' and '.', which stream registration
 * cannot actually use).
 */
#[Group('flysystem')]
#[CoversNothing]
final class X2FormatReplayTest extends TestCase {

  use DeprecationCaptureTrait;

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    new Settings([]);
    parent::tearDown();
  }

  /**
   * The locality predicate the validator consumes: only 'local' is known.
   *
   * @param string $plugin_id
   *   The plugin id being asked about.
   *
   * @return bool|null
   *   TRUE for local storage, NULL for anything unknown.
   */
  public function localMode(string $plugin_id): ?bool {
    return $plugin_id === 'local' ? TRUE : NULL;
  }

  /**
   * Builds a resolver over the settings facade knowing only 'local'.
   */
  private function resolver(): BindingResolver {
    return new BindingResolver(
      [new SettingsSource()],
      new SettingsValidator('/app-root', [$this, 'localMode']),
    );
  }

  /**
   * Replays the 2.x README's local-example entry: presented, inert keys kept.
   */
  public function testLocalExampleEntryReplaysUnchanged(): void {
    new Settings([
      'flysystem' => [
        'local-example' => [
          'driver' => 'local',
          'config' => [
            'root' => 'sites/default/files/flysystem',
            'public' => TRUE,
            'name' => 'Custom stream wrapper name',
            'description' => 'Custom description',
          ],
        ],
      ],
    ]);

    $source = new SettingsSource();
    $adapters = [];
    $this->captureDeprecations(function () use ($source, &$adapters): void {
      $adapters = $source->getAdapterDefinitions();
    });

    $this->assertSame([
      'local_example' => [
        'adapter' => 'local',
        'config' => [
          'root' => 'sites/default/files/flysystem',
          'public' => TRUE,
          'name' => 'Custom stream wrapper name',
          'description' => 'Custom description',
        ],
      ],
    ], $adapters, 'The 2.x entry presents as a local '
      . 'adapter definition; keys this module does not interpret pass through '
      . 'inert.');

    $resolved = NULL;
    $this->captureDeprecations(function () use (&$resolved): void {
      $resolved = $this->resolver()->resolve('local-example');
    });
    $this->assertNotNull(
      $resolved,
      'The replayed entry resolves to a working definition.'
    );
    $this->assertSame('local', $resolved['adapter']);
    $this->assertTrue(
      $resolved['publicly_accessible'],
      'config[public] answers the flag exactly as the 2.x plugin read it.'
    );
    $this->assertSame(
      'sites/default/files/flysystem',
      $resolved['config']['root']
    );
  }

  /**
   * Replays a 2.x ftp entry: the word rides through; unknown at resolve.
   */
  public function testRemoteDriverWordRidesThroughAndIsUnknown(): void {
    new Settings([
      'flysystem' => [
        'ftpexample' => [
          'driver' => 'ftp',
          'config' => [
            'host' => 'ftp.example.com',
            'username' => 'username',
            'password' => 'password',
            'port' => 21,
            'root' => '/path/to/root',
            'passive' => TRUE,
          ],
        ],
      ],
    ]);

    $adapters = [];
    $this->captureDeprecations(function () use (&$adapters): void {
      $adapters = (new SettingsSource())->getAdapterDefinitions();
    });
    $this->assertSame(
      'ftp',
      $adapters['ftpexample']['adapter'],
      'The 2.x driver word is carried through the layers unchanged.'
    );

    $this->expectException(UnknownAdapterPluginException::class);
    $this->expectExceptionMessageMatches('/ftp/');
    $this->captureDeprecations(function (): void {
      $this->resolver()->resolve('ftpexample');
    });
  }

  /**
   * Scheme names 2.x documented but PHP registration cannot actually use.
   *
   * @return array<string, array{string}>
   *   Scheme names outside the registerable grammar.
   */
  public static function illegalSchemeNames(): array {
    return [
      'dotted' => ['cdn.example'],
      'plus' => ['files+cdn'],
      'underscore' => ['my_files'],
    ];
  }

  /**
   * Verifies scheme names 2.x documented but PHP cannot register are rejected.
   *
   * @param string $scheme
   *   An illegal scheme name.
   */
  #[DataProvider('illegalSchemeNames')]
  public function testUndottedSchemeNamesAreRejected(string $scheme): void {
    $validator = new SettingsValidator('/app-root');

    $this->expectException(InvalidSchemeNameException::class);
    $validator->validateScheme($scheme, []);
  }

}
