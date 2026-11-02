<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Unit\Vocabulary;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards the module's single word for the storage plugin mechanism.
 *
 * The module calls the thing the upstream Flysystem library calls an
 * adapter: adapter. The 2.x-era synonym 'driver' survives in exactly
 * one place — the deprecated-alias translation seam in the settings
 * source, between its two marker comments. Everywhere else in the
 * production tree the old word must not appear: not in method names,
 * parameter names, exception messages, or docblocks. Vocabulary drift
 * reintroducing a second name for one concept fails this test.
 */
#[Group('flysystem')]
#[CoversNothing]
final class DriverWordVocabularyTest extends TestCase {

  /**
   * The file allowed to carry the deprecated word.
   */
  private const SEAM_FILE = 'Definition/SettingsSource.php';

  /**
   * Comment opening the alias translation seam.
   */
  private const SEAM_BEGIN = 'Deprecated-alias translation seam begin';

  /**
   * Comment closing the alias translation seam.
   */
  private const SEAM_END = 'Deprecated-alias translation seam end';

  /**
   * Verifies the deprecated word appears nowhere outside the seam.
   */
  public function testDriverWordOnlyInAliasSeam(): void {
    $moduleRoot = dirname(__DIR__, 4);
    $files = $this->collectPhpFiles($moduleRoot . '/src');
    $this->assertNotEmpty($files, 'The scan must actually find the production tree.');

    $violations = [];
    $seamHits = 0;
    foreach ($files as $file) {
      $relative = substr($file, strlen($moduleRoot . '/src/'));
      $lines = file($file);
      [$seamFrom, $seamTo] = $relative === self::SEAM_FILE
        ? $this->seamSpan($lines, $relative, $violations)
        : [NULL, NULL];
      foreach ($lines as $number => $text) {
        if (stripos($text, 'driver') === FALSE) {
          continue;
        }
        if ($seamFrom !== NULL && $number >= $seamFrom && $number <= $seamTo) {
          $seamHits++;
          continue;
        }
        $violations[] = sprintf(
          '%s:%d: %s',
          $relative,
          $number + 1,
          trim($text)
        );
      }
    }

    $this->assertGreaterThan(
      0,
      $seamHits,
      'The alias seam must actually translate the deprecated word — an empty seam is not a guard.'
    );
    $this->assertSame(
      [],
      $violations,
      "The word 'driver' may live only inside the deprecated-alias translation seam in " . self::SEAM_FILE . '; found elsewhere: ' . implode('; ', $violations)
    );
  }

  /**
   * Locates the seam span in the settings source, or records its absence.
   *
   * @param string[] $lines
   *   The file's lines.
   * @param string $relative
   *   The file path relative to src/, for violation messages.
   * @param string[] $violations
   *   Collected violations, passed by reference; a missing marker is one.
   *
   * @return array{0: int|null, 1: int|null}
   *   The zero-based line bounds of the seam, or NULLs when unmarked.
   */
  private function seamSpan(array $lines, string $relative, array &$violations): array {
    $from = NULL;
    $to = NULL;
    foreach ($lines as $number => $text) {
      if (strpos($text, self::SEAM_BEGIN) !== FALSE) {
        $from = $number;
      }
      if (strpos($text, self::SEAM_END) !== FALSE) {
        $to = $number;
      }
    }
    if ($from === NULL || $to === NULL || $to <= $from) {
      $violations[] = sprintf(
        '%s: the deprecated word is allowed only between markers "%s" and "%s"; the seam is not marked',
        $relative,
        self::SEAM_BEGIN,
        self::SEAM_END
      );
      return [NULL, NULL];
    }
    return [$from, $to];
  }

  /**
   * Collects every PHP file below a directory, sorted for determinism.
   *
   * @param string $dir
   *   The absolute directory to walk.
   *
   * @return string[]
   *   Absolute file paths.
   */
  private function collectPhpFiles(string $dir): array {
    $files = [];
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
      if ($file->isFile() && $file->getExtension() === 'php') {
        $files[] = $file->getPathname();
      }
    }
    sort($files);
    return $files;
  }

}
