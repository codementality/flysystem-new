<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Unit\Resolution;

use Drupal\flysystem\Definition\ArrayDefinitionSource;
use Drupal\flysystem\Exception\InvalidSettingsStructureException;
use Drupal\flysystem\Resolution\BindingResolver;
use Drupal\flysystem\Validation\SettingsValidator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Pins the meaning of a disabled binding.
 *
 * 'disabled' means ignore this entry: the scheme resolves to
 * core-native storage and the entry itself is not validated at all —
 * a parked binding never raises and never logs, even when its
 * remaining content is malformed. That is the only reading an
 * operator can assume from the word, and it lets a site park a
 * binding (with stale config left in place for later) without paying
 * an error on every page that touches the scheme.
 *
 * The boundary is asserted from both sides: an ENABLED malformed
 * binding still raises its typed error on touch (so the disabled
 * short-circuit cannot over-swing and silence real mistakes), while
 * the full-structure verification sweep still reports a parked
 * binding's defects — discoverable when asked, never in the way.
 */
#[Group('flysystem')]
#[CoversNothing]
final class BindingResolverDisabledSemanticsTest extends TestCase {

  /**
   * Builds a resolver over one settings-shaped source.
   */
  private function resolver(array $layer): BindingResolver {
    $is_local = static fn (string $plugin_id): ?bool => $plugin_id === 'local' ? TRUE : NULL;
    return new BindingResolver(
      [new ArrayDefinitionSource($layer)],
      new SettingsValidator('/app-root', $is_local),
    );
  }

  /**
   * Verifies a disabled entry with malformed contents resolves core-native silently.
   */
  public function testDisabledMalformedEntryResolvesCoreNativeSilently(): void {
    $resolver = $this->resolver([
      'adapters' => [
        'main' => ['plugin' => 'local', 'config' => ['root' => 'files']],
      ],
      'bindings' => [
        'parked' => [
          'adapter' => 'main',
          'disabled' => TRUE,
          'publicly_accessible' => 'not-a-boolean',
        ],
      ],
    ]);

    self::assertNull($resolver->resolve('parked'), 'A disabled entry must be ignored whole — its malformed sibling never surfaces.');
  }

  /**
   * Verifies a parked neighbor does not hold other schemes hostage.
   */
  public function testOtherSchemesResolveNormallyWithParkedNeighbor(): void {
    $resolver = $this->resolver([
      'adapters' => [
        'main' => ['plugin' => 'local', 'config' => ['root' => 'sites/default/files']],
      ],
      'bindings' => [
        'parked' => ['adapter' => 'nope', 'disabled' => TRUE, 'publicly_accessible' => 'junk'],
        'public' => ['adapter' => 'main'],
      ],
    ]);

    $resolved = $resolver->resolve('public');
    self::assertNotNull($resolved, 'A well-formed scheme resolves normally no matter what a parked entry holds.');
    self::assertSame('local', $resolved['plugin']);
  }

  /**
   * Verifies an enabled malformed binding still raises its typed error on touch.
   */
  public function testEnabledMalformedBindingStillRaises(): void {
    $resolver = $this->resolver([
      'adapters' => [],
      'bindings' => [
        'broken' => [
          'adapter' => 'main',
          'disabled' => FALSE,
          'publicly_accessible' => 'not-a-boolean',
        ],
      ],
    ]);

    $this->expectException(InvalidSettingsStructureException::class);
    $resolver->resolve('broken');
  }

  /**
   * Verifies the full sweep still reports a parked binding's defects.
   */
  public function testFullSweepStillReportsParkedDefects(): void {
    $is_local = static fn (string $plugin_id): ?bool => $plugin_id === 'local' ? TRUE : NULL;
    $validator = new SettingsValidator('/app-root', $is_local);

    $this->expectException(InvalidSettingsStructureException::class);
    $validator->assertValidStructure([
      'adapters' => [],
      'bindings' => [
        'parked' => [
          'adapter' => 'main',
          'disabled' => TRUE,
          'publicly_accessible' => 'not-a-boolean',
        ],
      ],
    ]);
  }

  /**
   * Verifies the sweep reports a parked binding carrying the rejected spelling.
   */
  public function testFullSweepReportsRejectedSpellingEvenOnDisabledBinding(): void {
    $is_local = static fn (string $plugin_id): ?bool => $plugin_id === 'local' ? TRUE : NULL;
    $validator = new SettingsValidator('/app-root', $is_local);

    $this->expectException(InvalidSettingsStructureException::class);
    $this->expectExceptionMessageMatches('/publicly_accessible.*config/');
    $validator->assertValidStructure([
      'adapters' => [],
      'bindings' => [
        'parked' => [
          'adapter' => 'main',
          'disabled' => TRUE,
          'rejected_keys' => ['publicly_accessible'],
        ],
      ],
    ]);
  }

}
