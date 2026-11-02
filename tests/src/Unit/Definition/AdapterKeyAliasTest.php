<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Unit\Definition;

use Drupal\Core\Site\Settings;
use Drupal\flysystem\Definition\SettingsSource;
use Drupal\flysystem\Resolution\BindingResolver;
use Drupal\flysystem\Validation\SettingsValidator;
use Drupal\Tests\flysystem\Traits\DeprecationCaptureTrait;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Pins the adapter-key grammar and its one deprecated alias.
 *
 * The canonical settings key naming the storage plugin is 'adapter' —
 * the word the upstream Flysystem library uses. The 2.x-era spelling
 * 'driver' keeps resolving as a deprecated alias so genuine 2.x files
 * stay operational; every use of the alias raises exactly one
 * E_USER_DEPRECATED notice naming the new spelling, and entries
 * carrying both keys resolve the canonical one while still noticing.
 * Everything else about entry shape keeps the existing inert-and-
 * validate-later behavior: an entry whose adapter word is missing or
 * not a string presents no definition at all.
 */
#[Group('flysystem')]
#[CoversNothing]
final class AdapterKeyAliasTest extends TestCase {

  use DeprecationCaptureTrait;

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    new Settings([]);
    parent::tearDown();
  }

  /**
   * Presents the flat layer, capturing any deprecation notices it raises.
   *
   * @param array $layer
   *   The flat flysystem settings layer.
   *
   * @return array{0: array, 1: array, 2: string[]}
   *   The adapter definitions, the scheme bindings, and the notices.
   */
  private function present(array $layer): array {
    new Settings(['flysystem' => $layer]);
    $adapters = [];
    $bindings = [];
    $notices = $this->captureDeprecations(function () use (&$adapters, &$bindings): void {
      $source = new SettingsSource();
      $adapters = $source->getAdapterDefinitions();
      $bindings = $source->getSchemeBindings();
    });
    return [$adapters, $bindings, $notices];
  }

  /**
   * Verifies the canonical key presents with no deprecation notice.
   */
  public function testCanonicalAdapterKeyNeedsNoAlias(): void {
    [$adapters, $bindings, $notices] = $this->present([
      'cdn' => ['adapter' => 'local', 'config' => ['root' => 'files']],
    ]);
    $this->assertSame([
      'cdn' => ['adapter' => 'local', 'config' => ['root' => 'files']],
    ], $adapters, "The canonical 'adapter' entry presents as a definition.");
    $this->assertSame(['cdn' => ['adapter' => 'cdn']], $bindings);
    $this->assertSame([], $notices, "The canonical spelling must never notice.");
  }

  /**
   * Verifies the deprecated alias resolves identically, with one notice.
   */
  public function testDeprecatedDriverKeyResolvesWithOneNotice(): void {
    [$adapters, $bindings, $notices] = $this->present([
      'cdn' => ['driver' => 'local', 'config' => ['root' => 'files']],
    ]);
    $this->assertSame([
      'cdn' => ['adapter' => 'local', 'config' => ['root' => 'files']],
    ], $adapters, 'The alias resolves to exactly what the canonical key gives.');
    $this->assertSame(['cdn' => ['adapter' => 'cdn']], $bindings);
    $this->assertCount(1, $notices, 'One use of the alias raises exactly one notice.');
    $this->assertStringContainsString('driver', $notices[0], 'The notice names the word in use.');
    $this->assertStringContainsString('adapter', $notices[0], 'The notice names the new spelling.');
    $this->assertStringContainsString('cdn', $notices[0], 'The notice names the scheme to fix.');
  }

  /**
   * Verifies an entry carrying both keys resolves the canonical one.
   */
  public function testBothKeysResolveAdapterAndStillNotice(): void {
    [$adapters, , $notices] = $this->present([
      'cdn' => ['adapter' => 'local', 'driver' => 'ftp', 'config' => ['root' => 'files']],
    ]);
    $this->assertSame('local', $adapters['cdn']['adapter'], 'The canonical key wins; the alias word is ignored for resolution.');
    $this->assertCount(1, $notices, 'A stale alias sitting beside the canonical key still points its user at the new spelling.');
  }

  /**
   * Verifies the notice is per entry, not per layer.
   */
  public function testEveryAliasedEntryNotices(): void {
    [, , $notices] = $this->present([
      'one' => ['driver' => 'local'],
      'two' => ['driver' => 'local'],
      'three' => ['adapter' => 'local'],
    ]);
    $this->assertCount(2, $notices, 'Each entry still using the alias gets its own notice; the canonical one gets none.');
  }

  /**
   * Verifies presence of the alias notices even when it cannot resolve.
   */
  public function testNonStringAliasStillNoticesAndPresentsNothing(): void {
    [$adapters, $bindings, $notices] = $this->present([
      'cdn' => ['driver' => 42],
    ]);
    $this->assertSame([], $adapters, 'A non-string word presents no definition, exactly as before.');
    $this->assertSame([], $bindings);
    $this->assertCount(1, $notices, 'A misshapen alias use is still an alias use; the notice is the pointer out.');
  }

  /**
   * Verifies a non-string canonical word notices nothing and presents nothing.
   */
  public function testNonStringCanonicalKeyPresentsNothing(): void {
    [$adapters, $bindings, $notices] = $this->present([
      'cdn' => ['adapter' => 42],
    ]);
    $this->assertSame([], $adapters);
    $this->assertSame([], $bindings);
    $this->assertSame([], $notices, 'The canonical key misused is a shape problem for the validator, not a deprecation.');
  }

  /**
   * Verifies the full pipeline runs on an aliased entry — the upgrade runway.
   */
  public function testAliasedEntryResolvesThroughTheResolver(): void {
    new Settings([
      'flysystem' => [
        'cdn' => ['driver' => 'local', 'config' => ['root' => 'files']],
      ],
    ]);
    $resolver = new BindingResolver(
      [new SettingsSource()],
      new SettingsValidator('/app-root'),
    );
    $resolved = NULL;
    $this->captureDeprecations(function () use ($resolver, &$resolved): void {
      $resolved = $resolver->resolve('cdn');
    });
    $this->assertNotNull($resolved, 'A 2.x-format entry resolves through the alias.');
    $this->assertSame('local', $resolved['adapter'], 'The resolved definition carries the adapter word.');
  }

}
