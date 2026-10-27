<?php

declare(strict_types=1);

namespace Drupal\flysystem\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards that every shipped comment is self-contained.
 *
 * Code shipped with this module — production files, tests, YAML, and
 * composer metadata — is read by site builders and support volunteers
 * who have no access to internal design discussions. Comments that
 * reference private trackers, milestone shorthand, decision codes, or
 * pull-request discussions are unreadable noise outside this repository
 * and seed confusion in public queues. This test fails whenever shipped
 * text reintroduces such references, so the rule enforces itself instead
 * of relying on memory.
 *
 * The patterns live in code lines, which this scan does not inspect;
 * this file's own comments stay free of the references they catch.
 */
#[Group('flysystem')]
#[CoversNothing]
final class CommentsAreSelfContainedTest extends TestCase {

  /**
   * The module root, located from this file.
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
   * Returns the forbidden comment patterns.
   *
   * @return array<string, string>
   *   Label => PCRE pattern, each assembled to avoid self-matching.
   */
  private function patterns(): array {
    return [
      'decision-code' => '/\bD[0-9]+\b/',
      'open-item-code' => '/\bB[0-9]+[a-z]?\b/',
      'section-sign' => '/' . chr(0xC2) . chr(0xA7) . '/',
      'milestone-shorthand' => '/\bM[0-9]+(-[0-9]+)?\b/',
      'tracker-reference' => '/(?:issue|pull request|PR)\s*#[0-9]/i',
      'internal-shorthand' => '/owner ruling|plan row|the row\b|row gate|review thread|triage queue/i',
    ];
  }

  /**
   * Verifies no shipped text carries internal-reference comment patterns.
   */
  public function testShippedCommentsAreSelfContained(): void {
    $violations = [];
    foreach ($this->shippedFiles() as $file) {
      $lines = preg_split('/\R/', (string) file_get_contents($file)) ?: [];
      $relative = str_replace($this->moduleRoot . '/', '', $file);
      foreach ($lines as $number => $line) {
        if (!$this->isCommentLine($line, $file)) {
          continue;
        }
        foreach ($this->patterns() as $label => $pattern) {
          if (preg_match($pattern, $line)) {
            $violations[] = sprintf('%s:%d [%s] %s', $relative, $number + 1, $label, trim($line));
          }
        }
      }
    }
    self::assertSame([], $violations, "Shipped comments must stand on their own; rewrite internal references in plain terms:\n" . implode("\n", $violations));
  }

  /**
   * TRUE when the line is a comment (PHP doc/line or YAML/hash style).
   */
  private function isCommentLine(string $line, string $file): bool {
    $trimmed = ltrim($line);
    if (str_ends_with($file, '.php')) {
      return str_starts_with($trimmed, '*')
        || str_starts_with($trimmed, '/*')
        || str_starts_with($trimmed, '//')
        || str_starts_with($trimmed, '#');
    }
    return str_starts_with($trimmed, '#');
  }

  /**
   * Collects shipped text files: src/, tests/, module root YAML/JSON/info.
   *
   * @return string[]
   *   Absolute file paths.
   */
  private function shippedFiles(): array {
    $files = [];
    $roots = [$this->moduleRoot . '/src', $this->moduleRoot . '/tests'];
    foreach ($roots as $root) {
      $iterator = new \RecursiveIteratorIterator(
        new \RecursiveCallbackFilterIterator(
          new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
          static fn(\SplFileInfo $current): bool => !in_array($current->getFilename(), ['.git', 'vendor'], TRUE),
        ),
      );
      foreach ($iterator as $file) {
        if ($file->isFile() && preg_match('/\.(php|module|install|inc)$/', $file->getFilename())) {
          $files[] = $file->getPathname();
        }
      }
    }
    foreach (['flysystem.services.yml', 'flysystem.info.yml', 'composer.json', 'README.md', 'DEVELOPER_NOTES.md'] as $name) {
      if (is_file($this->moduleRoot . '/' . $name)) {
        $files[] = $this->moduleRoot . '/' . $name;
      }
    }
    return $files;
  }

}
