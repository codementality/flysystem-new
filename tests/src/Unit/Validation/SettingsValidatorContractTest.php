<?php

declare(strict_types=1);

namespace Drupal\flysystem\Tests\Unit\Validation;

use Drupal\flysystem\Exception\InvalidAdapterIdException;
use Drupal\flysystem\Exception\InvalidSchemeNameException;
use Drupal\flysystem\Exception\InvalidSettingsStructureException;
use Drupal\flysystem\Exception\PublicRootOutsideWebrootException;
use Drupal\flysystem\Exception\UnknownAdapterPluginException;
use Drupal\flysystem\Validation\SettingsValidator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests the settings.php schema validator: constraints and containment.
 *
 * Covers the contract matrix: scheme/adapter-id name constraints,
 * non-array/type violations, unknown plugin ids, and the full
 * webroot-containment matrix — all lazily: touching scheme X surfaces
 * X's errors only (a broken binding errors only when used, never at
 * container build or install). A full-layer sweep exists separately for
 * the verification API.
 *
 * Scheme grammar is the intersection of the two real grammars a bound
 * scheme must survive, so "anything bindable is usable" in both
 * directions: PHP's stream_wrapper_register() rejects underscores
 * outright (verified live: "Invalid protocol scheme specified"), while
 * accepting letters, digits, '-', '.', '+'; core's own scheme parser —
 * preg_match('/^([\w\-]+):\/\//', ...) in
 * web/core/lib/Drupal/Core/StreamWrapper/StreamWrapperManager.php:269-276
 * — cannot parse '.' or '+'. What both accept: ^[A-Za-z0-9-]+$.
 *
 * The local-versus-remote question is asked through an injectable
 * predicate (plugin id => TRUE local / FALSE remote / NULL unknown); the
 * adapter plugin manager's discovery data supplies the answer at wiring
 * time. The un-injected default recognizes only 'local'.
 */
#[Group('flysystem')]
#[CoversNothing]
final class SettingsValidatorContractTest extends TestCase {

  /**
   * Synthetic webroot used for all containment checks.
   */
  private const APP_ROOT = '/app-root';

  /**
   * A valid local adapter definition.
   *
   * @return array<string, mixed>
   *   Adapter layer entry.
   */
  private static function localDefinition(string $root): array {
    return ['plugin' => 'local', 'config' => ['root' => $root]];
  }

  /**
   * Builds a one-binding test layer.
   *
   * @param string $scheme
   *   The scheme to bind.
   * @param array<string, mixed> $binding
   *   The binding entry.
   * @param array<string, mixed> $adapters
   *   The adapters section.
   *
   * @return array<string, mixed>
   *   A flysystem-shaped settings layer.
   */
  private static function layerFor(string $scheme, array $binding, ?array $adapters = NULL): array {
    return [
      'adapters' => $adapters ?? ['main' => self::localDefinition('sites/default/files')],
      'bindings' => [$scheme => $binding],
    ];
  }

  /**
   * Validator with a standard two-plugin universe: local + remote-s3.
   */
  private static function validator(): SettingsValidator {
    return new SettingsValidator(self::APP_ROOT, static fn (string $pluginId): ?bool => match ($pluginId) {
      'local' => TRUE,
      's3' => FALSE,
      default => NULL,
    });
  }

  /**
   * Verifies the documented name patterns are the validator's constants.
   */
  public function testNamePatternsAreTheDocumentedVocabulary(): void {
    self::assertSame('/^[A-Za-z0-9-]+$/', SettingsValidator::SCHEME_PATTERN, 'Scheme grammar must be the intersection of PHP-registration and core-parsing grammars (see class docblock).');
    self::assertSame('/^[a-z0-9_]+$/', SettingsValidator::ADAPTER_ID_PATTERN, 'Adapter id grammar is lowercase alphanumeric plus underscore.');
  }

  /**
   * Valid scheme names parse.
   */
  #[DataProvider('validSchemeProvider')]
  public function testValidSchemeNamesAccepted(string $scheme): void {
    $validator = self::validator();
    $layer = self::layerFor($scheme, ['adapter' => 'main']);
    $validator->validateScheme($scheme, $layer);
    $this->expectNotToPerformAssertions();
  }

  /**
   * Provides core-registerable scheme names.
   *
   * @return array<string, array{0: string}>
   *   Scheme names core itself would register.
   */
  public static function validSchemeProvider(): array {
    return [
      'public' => ['public'],
      'private' => ['private'],
      'temporary' => ['temporary'],
      'custom with digits' => ['backup2'],
      'dashes' => ['my-scheme-2'],
      'uppercase' => ['Public'],
    ];
  }

  /**
   * Invalid scheme names produce a typed, actionable exception.
   */
  #[DataProvider('invalidSchemeProvider')]
  public function testInvalidSchemeNamesRejected(string $scheme): void {
    $this->expectException(InvalidSchemeNameException::class);
    $this->expectExceptionMessageMatches('/' . preg_quote($scheme, '/') . '/');
    self::validator()->validateScheme($scheme, self::layerFor($scheme, ['adapter' => 'main']));
  }

  /**
   * Provides names outside core's scheme grammar.
   *
   * @return array<string, array{0: string}>
   *   Scheme names outside core's grammar.
   */
  public static function invalidSchemeProvider(): array {
    return [
      'empty' => [''],
      'underscore' => ['my_scheme'],
      'space' => ['has space'],
      'colon' => ['co:lon'],
      'dot' => ['a.b'],
      'plus' => ['up+er'],
      'path escape' => ['../x'],
    ];
  }

  /**
   * An unbound, validly-named scheme is not an error (core-native coexistence).
   */
  public function testUnboundSchemeIsValid(): void {
    $validator = self::validator();
    $validator->validateScheme('nobody-here', ['adapters' => [], 'bindings' => []]);
    $this->expectNotToPerformAssertions();
  }

  /**
   * Invalid adapter ids referenced by the touched binding are rejected.
   */
  #[DataProvider('invalidAdapterIdProvider')]
  public function testInvalidAdapterIdsRejected(string $adapterId): void {
    $this->expectException(InvalidAdapterIdException::class);
    self::validator()->validateScheme('public', self::layerFor('public', ['adapter' => $adapterId]));
  }

  /**
   * Provides adapter ids outside the declared grammar.
   *
   * @return array<string, array{0: string}>
   *   Adapter ids outside the declared grammar.
   */
  public static function invalidAdapterIdProvider(): array {
    return [
      'empty' => [''],
      'uppercase' => ['Main'],
      'dash' => ['has-dash'],
      'space' => ['has space'],
      'dot' => ['a.b'],
    ];
  }

  /**
   * Structural violations produce InvalidSettingsStructureException.
   */
  #[DataProvider('structureViolationProvider')]
  public function testStructureViolationsRejected(array $layer, string $scheme): void {
    $this->expectException(InvalidSettingsStructureException::class);
    self::validator()->validateScheme($scheme, $layer);
  }

  /**
   * Provides malformed layers paired with the scheme touched.
   *
   * @return array<string, array{0: array<string, mixed>, 1: string}>
   *   Malformed layers paired with the scheme touched.
   */
  public static function structureViolationProvider(): array {
    $goodDef = ['main' => self::localDefinition('r')];
    $goodBinding = ['public' => ['adapter' => 'main']];
    return [
      'bindings section non-array' => [
        ['adapters' => $goodDef, 'bindings' => 'oops'],
        'public',
      ],
      'adapters section non-array' => [
        ['adapters' => 'oops', 'bindings' => $goodBinding],
        'public',
      ],
      'binding entry non-array' => [
        ['adapters' => $goodDef, 'bindings' => ['public' => 'nope']],
        'public',
      ],
      'definition entry non-array' => [
        ['adapters' => ['main' => 'nope'], 'bindings' => $goodBinding],
        'public',
      ],
      'plugin not string' => [
        ['adapters' => ['main' => ['plugin' => 123]], 'bindings' => $goodBinding],
        'public',
      ],
      'plugin missing' => [
        ['adapters' => ['main' => ['config' => []]], 'bindings' => $goodBinding],
        'public',
      ],
      'config not array' => [
        ['adapters' => ['main' => ['plugin' => 'local', 'config' => 'oops']], 'bindings' => $goodBinding],
        'public',
      ],
      'adapter key not string' => [
        ['adapters' => $goodDef, 'bindings' => ['public' => ['adapter' => ['nested']]]],
        'public',
      ],
      'disabled not bool' => [
        ['adapters' => $goodDef, 'bindings' => ['public' => ['adapter' => 'main', 'disabled' => 'yes']]],
        'public',
      ],
      'publicly_accessible not bool' => [
        ['adapters' => $goodDef, 'bindings' => ['public' => ['adapter' => 'main', 'publicly_accessible' => '1']]],
        'public',
      ],
      'root not string' => [
        ['adapters' => ['main' => ['plugin' => 'local', 'config' => ['root' => 42]]], 'bindings' => $goodBinding],
        'public',
      ],
    ];
  }

  /**
   * Structural cleanliness is judged per-touched-scheme, not whole-file.
   */
  public function testBrokenUnreferencedSectionsDoNotAffectValidSchemes(): void {
    $layer = [
      'adapters' => ['main' => self::localDefinition('sites/default/files'), 'bad_upper' => ['plugin' => 123]],
      'bindings' => ['public' => ['adapter' => 'main'], 'zoo' => 'garbage'],
    ];
    self::validator()->validateScheme('public', $layer);
    $this->expectNotToPerformAssertions();
  }

  /**
   * But a full sweep (the verification API) surfaces every planted defect.
   */
  public function testFullSweepSurfacesAllDefects(): void {
    $this->expectException(InvalidSettingsStructureException::class);
    $layer = [
      'adapters' => ['main' => self::localDefinition('ok')],
      'bindings' => ['public' => ['adapter' => 'main'], 'zoo' => 'garbage'],
    ];
    self::validator()->assertValidStructure($layer);
  }

  /**
   * An unknown plugin id surfaces the typed exception at validation.
   */
  public function testUnknownPluginRejectedWithTypedException(): void {
    $this->expectException(UnknownAdapterPluginException::class);
    $this->expectExceptionMessageMatches('/cloud_no_such/');
    $layer = self::layerFor('public', ['adapter' => 'main'], ['main' => ['plugin' => 'cloud_no_such']]);
    self::validator()->validateScheme('public', $layer);
  }

  /**
   * The default (un-injected) validator recognizes 'local' and nothing more.
   */
  public function testDefaultValidatorTreatsUnknownPluginsAsErrors(): void {
    $this->expectException(UnknownAdapterPluginException::class);
    $validator = new SettingsValidator(self::APP_ROOT);
    $layer = self::layerFor('public', ['adapter' => 'main'], ['main' => ['plugin' => 's3']]);
    $validator->validateScheme('public', $layer);
  }

  /**
   * The webroot containment matrix.
   */
  #[DataProvider('containmentMatrixProvider')]
  public function testWebrootContainmentMatrix(array $binding, array $adapters, ?string $expectedError): void {
    $layer = ['adapters' => $adapters, 'bindings' => ['the-scheme' => $binding]];
    // The scheme name is irrelevant to the matrix; use 'the-scheme' so no
    // default-public special-casing applies except where asserted.
    $validator = self::validator();
    if ($expectedError === NULL) {
      $validator->validateScheme('the-scheme', $layer);
      $this->expectNotToPerformAssertions();
      return;
    }
    $this->expectException($expectedError);
    $validator->validateScheme('the-scheme', $layer);
  }

  /**
   * Provides the webroot containment matrix rows.
   *
   * @return array<string, array{0: array, 1: array, 2: string|null}>
   *   Rows: binding, adapters, expected exception class or NULL.
   */
  public static function containmentMatrixProvider(): array {
    $inside = ['main' => self::localDefinition('sites/default/files')];
    $outsideAbs = ['main' => self::localDefinition('/srv/elsewhere')];
    $public = ['adapter' => 'main', 'publicly_accessible' => TRUE];
    return [
      'public + local + inside relative root passes' => [
        $public,
        $inside,
        NULL,
      ],
      'public + local + inside absolute root passes' => [
        $public,
        ['main' => self::localDefinition('/app-root/inside')],
        NULL,
      ],
      'public + local + outside relative root rejected' => [
        $public,
        ['main' => self::localDefinition('../public-outside')],
        PublicRootOutsideWebrootException::class,
      ],
      'public + local + outside absolute root rejected' => [
        $public,
        $outsideAbs,
        PublicRootOutsideWebrootException::class,
      ],
      'non-public + local + outside passes' => [
        ['adapter' => 'main'],
        ['main' => self::localDefinition('/../private-files')],
        NULL,
      ],
      'non-public + local + relative escape passes' => [
        ['adapter' => 'main'],
        ['main' => self::localDefinition('../private-files')],
        NULL,
      ],
      'custom scheme defaults non-public, outside passes' => [
        ['adapter' => 'main'],
        $inside,
        NULL,
      ],
      'custom scheme explicitly public + outside rejected' => [
        $public,
        $outsideAbs,
        PublicRootOutsideWebrootException::class,
      ],
      'remote plugin is exempt from containment' => [
        $public,
        ['main' => ['plugin' => 's3', 'config' => ['bucket' => 'anything-outside']]],
        NULL,
      ],
      'public local with no root configured cannot be verified' => [
        $public,
        ['main' => ['plugin' => 'local']],
        PublicRootOutsideWebrootException::class,
      ],
      'disabled binding skips containment' => [
        ['adapter' => 'main', 'publicly_accessible' => TRUE, 'disabled' => TRUE],
        $outsideAbs,
        NULL,
      ],
    ];
  }

  /**
   * Public-only core scheme: 'public' alone defaults publicly_accessible TRUE.
   */
  public function testPublicCoreSchemeDefaultsToPubliclyAccessible(): void {
    $this->expectException(PublicRootOutsideWebrootException::class);
    $layer = self::layerFor('public', ['adapter' => 'main'], ['main' => self::localDefinition('/srv/elsewhere')]);
    self::validator()->validateScheme('public', $layer);
  }

  /**
   * An explicit FALSE override releases a core public scheme from containment.
   */
  public function testExplicitNonPublicOverridePassesForCorePublic(): void {
    $layer = self::layerFor('public', ['adapter' => 'main', 'publicly_accessible' => FALSE], ['main' => self::localDefinition('/srv/elsewhere')]);
    self::validator()->validateScheme('public', $layer);
    $this->expectNotToPerformAssertions();
  }

  /**
   * Containment rejections name the resolved path (actionable errors).
   */
  public function testContainmentRejectionNamesResolvedPath(): void {
    $layer = self::layerFor('public', ['adapter' => 'main', 'publicly_accessible' => TRUE], ['main' => self::localDefinition('../public-outside')]);
    try {
      self::validator()->validateScheme('public', $layer);
      self::fail('Expected PublicRootOutsideWebrootException.');
    }
    catch (PublicRootOutsideWebrootException $e) {
      self::assertStringContainsString('/public-outside', $e->getMessage(), 'Lexically resolved path must appear in the error (anchored to app root, never realpath).');
      self::assertStringContainsString('public', $e->getMessage());
    }
  }

  /**
   * Roots containing interior '..' collapse lexically before the check.
   */
  public function testInteriorDotSegmentsCollapseLexically(): void {
    $layer = self::layerFor('public', ['adapter' => 'main', 'publicly_accessible' => TRUE], ['main' => self::localDefinition('sites/default/../default/files')]);
    self::validator()->validateScheme('public', $layer);
    $this->expectNotToPerformAssertions();
  }

  /**
   * Lazy timing: a defect in binding B never fires while touching scheme A.
   */
  public function testErrorsAreLazyPerTouchedScheme(): void {
    $layer = [
      'adapters' => ['main' => self::localDefinition('ok')],
      'bindings' => [
        'public' => ['adapter' => 'main'],
        'private' => ['adapter' => 'main', 'publicly_accessible' => TRUE],
      ],
    ];
    // 'private' binding is not itself broken; make it invalid by giving
    // its adapter an outside root under a second definition.
    $layer['adapters']['secret'] = self::localDefinition('/srv/outside');
    $privateBinding = ['adapter' => 'secret', 'publicly_accessible' => TRUE];
    $layer['bindings']['private'] = $privateBinding;
    self::validator()->validateScheme('public', $layer);
    $this->expectException(PublicRootOutsideWebrootException::class);
    self::validator()->validateScheme('private', $layer);
  }

}
