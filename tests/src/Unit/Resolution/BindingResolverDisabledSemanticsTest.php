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
 * 'disabled' means ignore this entry: the scheme resolves to core-native
 * storage and the entry is not validated at all — a parked binding never
 * raises and never logs, even when its remaining content is malformed.
 * That is the only reading an operator can assume from the word, and it
 * lets a site park a binding (stale config left in place for later)
 * without paying an error on every page that touches the scheme.
 *
 * The boundary is asserted from both sides: an enabled malformed binding
 * still raises its typed error on touch — so the disabled short-circuit
 * cannot over-swing and silence real mistakes — while the full-structure
 * verification sweep still reports a parked binding's defects,
 * discoverable when asked, never in the way.
 */
#[Group('flysystem')]
#[CoversNothing]
final class BindingResolverDisabledSemanticsTest extends TestCase {

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
   * Builds a resolver over one settings-shaped source.
   */
  private function resolver(array $layer): BindingResolver {
    return new BindingResolver(
      [new ArrayDefinitionSource($layer)],
      new SettingsValidator('/app-root', [$this, 'localMode']),
    );
  }

  /**
   * A disabled entry with malformed contents resolves core-native silently.
   */
  public function testDisabledMalformedResolvesCoreNativeSilently(): void {
    $resolver = $this->resolver([
      'adapters' => [
        'main' => [
          'plugin' => 'local',
          'config' => ['root' => 'files'],
        ],
      ],
      'bindings' => [
        'parked' => [
          'adapter' => 'main',
          'disabled' => TRUE,
          'publicly_accessible' => 'not-a-boolean',
        ],
      ],
    ]);

    $this->assertNull(
      $resolver->resolve('parked'),
      'A disabled entry is ignored whole; its malformed sibling never surfaces.'
    );
  }

  /**
   * A parked neighbor does not hold other schemes hostage.
   */
  public function testOtherSchemesResolveWithParkedNeighbor(): void {
    $resolver = $this->resolver([
      'adapters' => [
        'main' => [
          'plugin' => 'local',
          'config' => ['root' => 'sites/default/files'],
        ],
      ],
      'bindings' => [
        'parked' => [
          'adapter' => 'nope',
          'disabled' => TRUE,
          'publicly_accessible' => 'junk',
        ],
        'public' => ['adapter' => 'main'],
      ],
    ]);

    $resolved = $resolver->resolve('public');
    $this->assertNotNull(
      $resolved,
      'A well-formed scheme resolves normally regardless of a parked entry.'
    );
    $this->assertSame('local', $resolved['plugin']);
  }

  /**
   * An enabled malformed binding still raises its typed error on touch.
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
   * The full sweep still reports a parked binding's malformed content.
   */
  public function testFullSweepStillReportsParkedDefects(): void {
    $validator = new SettingsValidator('/app-root', [$this, 'localMode']);

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
   * The sweep reports the rejected spelling even on a disabled binding.
   */
  public function testSweepReportsRejectedSpellingOnDisabled(): void {
    $validator = new SettingsValidator('/app-root', [$this, 'localMode']);

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
