<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Unit\Definition;

use Drupal\Core\Site\Settings;
use Drupal\flysystem\Definition\SettingsSource;
use Drupal\flysystem\Exception\InvalidSettingsStructureException;
use Drupal\flysystem\Resolution\BindingResolver;
use Drupal\flysystem\Validation\SettingsValidator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests the public-flag spelling of the settings grammar.
 *
 * The published 2.x module spelled the publicly-accessible answer as a
 * boolean inside the adapter config — its local plugin constructed
 * itself from $configuration['public'] (verified in the 2.2.x source of
 * the old module). The internal binding layer's own name for the same
 * answer must never become a user-facing key: only config['public'] is
 * recognized. A site that writes the entry-level spelling gets a typed,
 * guiding error at the touched scheme, so an invented spelling fails
 * loudly instead of silently changing what serves the scheme.
 *
 * The error keeps the lazy, scheme-local timing every settings complaint
 * obeys: it fires when that scheme is touched, other schemes resolve
 * normally, and a disabled entry (which ignores everything) stays silent
 * even with the rejected spelling present.
 */
#[Group('flysystem')]
#[CoversNothing]
final class SettingsSourcePublicFlagParityTest extends TestCase {

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    new Settings([]);
    parent::tearDown();
  }

  /**
   * Builds a resolver over the settings.php facade with the local plugin known.
   */
  private function resolver(): BindingResolver {
    $is_local = static fn (string $plugin_id): ?bool => $plugin_id === 'local' ? TRUE : NULL;
    return new BindingResolver(
      [new SettingsSource()],
      new SettingsValidator('/app-root', $is_local),
    );
  }

  /**
   * Verifies config['public'] is the recognized flag for both answers.
   */
  public function testConfigPublicIsTheCanonicalFlag(): void {
    new Settings([
      'flysystem' => [
        'assets' => [
          'driver' => 'local',
          'config' => [
            'root' => 'sites/default/files/assets',
            'public' => TRUE,
          ],
        ],
        'archive' => [
          'driver' => 'local',
          'config' => [
            'root' => 'sites/default/files/archive',
            'public' => FALSE,
          ],
        ],
      ],
    ]);

    $assets = $this->resolver()->resolve('assets');
    $archive = $this->resolver()->resolve('archive');

    self::assertNotNull($assets);
    self::assertTrue($assets['publicly_accessible'], "config['public'] = TRUE must answer publicly accessible for a new scheme.");
    self::assertNotNull($archive);
    self::assertFalse($archive['publicly_accessible'], "config['public'] = FALSE must answer not publicly accessible — the flag is honored, not tolerated.");
  }

  /**
   * Verifies the entry-level spelling is a typed, guiding rejection.
   */
  public function testEntryLevelSpellingIsRejected(): void {
    new Settings([
      'flysystem' => [
        'cdn' => [
          'driver' => 'local',
          'config' => [
            'root' => 'sites/default/files/cdn',
          ],
          'publicly_accessible' => TRUE,
        ],
        'other' => [
          'driver' => 'local',
          'config' => [
            'root' => 'sites/default/files/other',
            'public' => TRUE,
          ],
        ],
      ],
    ]);

    $resolver = $this->resolver();

    self::assertNotNull($resolver->resolve('other'), 'One entry\'s rejected spelling must not hold other schemes hostage — the error is lazy and scheme-local.');

    $this->expectException(InvalidSettingsStructureException::class);
    $this->expectExceptionMessageMatches('/\[publicly_accessible\].*config\[.public.\]/');
    $resolver->resolve('cdn');
  }

  /**
   * Verifies disabled ignores everything, including a rejected spelling.
   */
  public function testDisabledEntryWithRejectedSpellingStaysSilent(): void {
    new Settings([
      'flysystem' => [
        'old' => [
          'driver' => 'local',
          'config' => [
            'root' => 'sites/default/files/old',
          ],
          'publicly_accessible' => TRUE,
          'disabled' => TRUE,
        ],
      ],
    ]);

    self::assertNull($this->resolver()->resolve('old'), 'Disabled means the entry is ignored — parked junk, even a rejected spelling, raises nothing at touch time.');
  }

}
