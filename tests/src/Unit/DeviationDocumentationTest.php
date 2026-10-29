<?php

declare(strict_types=1);

namespace Drupal\flysystem\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Keeps the behavior documentation honest against the test suite.
 *
 * The module ships a plain-language document describing where a bound
 * local storage scheme answers like core and where it deliberately does
 * not. This contract keeps the two in step in both directions: every
 * test named in the document must exist in this suite, and every test
 * that proves a documented behavior difference must be named there. If
 * a behavior change renames or removes a test, this fails until the
 * document follows it — documentation drift is a defect.
 */
#[Group('flysystem')]
#[CoversNothing]
final class DeviationDocumentationTest extends TestCase {

  /**
   * The test classes whose suites pin the documented behavior answers.
   *
   * Each of these proves some part of what the document describes:
   * handle byte semantics and locking, metadata answers and their
   * synthesis, traversal shapes, soft refusals instead of fatals,
   * binding-error refusals, the managed mechanics at the service layer,
   * and the coexistence and URL facts. The document must cite all of
   * them.
   *
   * @var string[]
   */
  private const DOCUMENTED_TEST_CLASSES = [
    'WrapperHandleSemanticsTest',
    'WrapperHandleEndToEndTest',
    'WrapperMetadataOperationsTest',
    'WrapperMetadataEndToEndTest',
    'PathNormalizationContractTest',
    'TraversalMatrixTest',
    'GuardRefusalSoftFailureTest',
    'BrokenBindingGracefulFailureTest',
    'BrokenBindingMetadataRefusalTest',
    'WrapperBindingErrorsTest',
    'WrapperUrlAndUiSurfaceTest',
    'MixedBindingCoexistenceTest',
    'ManagedFileMechanicsTest',
    'ManagedFileUploadTest',
    'WrapperRegistrationCoexistenceTest',
  ];

  /**
   * Absolute path of the module under test.
   */
  private string $moduleRoot;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->moduleRoot = dirname(__DIR__, 3);
  }

  /**
   * The behavior document ships and covers every answer callers use.
   */
  public function testBehaviorDocumentExistsAndCoversTheCallFamilies(): void {
    $doc = @file_get_contents($this->moduleRoot . '/BEHAVIOR.md');
    self::assertNotFalse($doc, 'The module must ship a plain-language behavior document (BEHAVIOR.md).');
    foreach ([
      'reading and writing files',
      'locks are different',
      'file metadata',
      'touching files',
      'permissions work through',
      'ownership changes are refused',
      'directories and renames',
      'real paths are lexical',
      'paths that try to escape',
      'broken configuration fails',
      'uploads, managed files, image styles',
      'bindings mix freely',
      'names and listings',
    ] as $family) {
      self::assertStringContainsString(strtolower($family), strtolower($doc), 'The document must answer for: $family.');
    }
  }

  /**
   * Every test the document cites exists in this suite.
   */
  public function testEveryCitedTestExists(): void {
    $doc = (string) @file_get_contents($this->moduleRoot . '/BEHAVIOR.md');
    preg_match_all('/`([A-Z][A-Za-z0-9]*Test)`/', $doc, $matches);
    self::assertNotEmpty($matches[1], 'The document must cite the tests that prove each answer.');
    $known = $this->testClasses();
    foreach (array_unique($matches[1]) as $cited) {
      self::assertContains($cited, $known, "The document cites a test that does not exist: $cited.");
    }
  }

  /**
   * Every behavior-proving test is cited by the document.
   */
  public function testEveryBehaviorTestIsCited(): void {
    $doc = (string) @file_get_contents($this->moduleRoot . '/BEHAVIOR.md');
    foreach (self::DOCUMENTED_TEST_CLASSES as $class) {
      self::assertStringContainsString("`$class`", $doc, "The behavior test $class is not cited by the behavior document.");
    }
  }

  /**
   * The document speaks plain words: no internal shorthand, no codes.
   */
  public function testDocumentCarriesNoInternalShorthand(): void {
    $doc = (string) @file_get_contents($this->moduleRoot . '/BEHAVIOR.md');
    foreach (['M1-', 'M2-', 'D5', '§', 'PR #', 'issue #'] as $token) {
      self::assertStringNotContainsString($token, $doc, "Internal shorthand must not ship in the behavior document: $token");
    }
  }

  /**
   * All test class names discoverable in this suite, by filename.
   *
   * @return string[]
   *   Basenames without extension of every *Test.php under tests/src.
   */
  private function testClasses(): array {
    $found = [];
    $directory = new \RecursiveDirectoryIterator($this->moduleRoot . '/tests/src');
    foreach (new \RecursiveIteratorIterator($directory) as $file) {
      $name = $file->getFilename();
      if (str_ends_with($name, 'Test.php')) {
        $found[] = substr($name, 0, -4);
      }
    }
    return $found;
  }

}
