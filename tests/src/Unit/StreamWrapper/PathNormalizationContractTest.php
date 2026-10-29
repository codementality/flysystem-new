<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Unit\StreamWrapper;

use Drupal\flysystem\StreamWrapper\FlysystemPublicStream;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests the wrapper's path-to-operator delegation contract.
 *
 * The storage abstraction's traversal guard lives at the operator, which
 * runs a path normalizer before the backend sees anything — that guard is
 * only real if the wrapper hands the operator the RAW relative path and
 * lets the operator decide. This proves the wrapper performs no
 * pre-collapsing, no pre-escaping, and no backslash translation of its
 * own: those behaviors belong to one place (the operator) so the guard is
 * engaged, not silently reimplemented or bypassed at the seam. The target
 * extraction is the seam's only path work, and it strips only the scheme
 * and the erroneous leading/trailing slashes — never an interior '..'.
 */
#[Group('flysystem')]
#[CoversNothing]
final class PathNormalizationContractTest extends TestCase {

  /**
   * Calls the wrapper's protected target extraction directly.
   */
  private function target(string $uri): string {
    $wrapper = new FlysystemPublicStream();
    $method = new \ReflectionMethod($wrapper, 'getTarget');
    return $method->invoke($wrapper, $uri);
  }

  /**
   * An interior dot-dot reaches the operator untouched, for it to collapse.
   */
  public function testInteriorDotDotIsPassedThroughRaw(): void {
    self::assertSame('sub/../file.txt', $this->target('public://sub/../file.txt'));
  }

  /**
   * A leading escape is passed through raw, so the operator can reject it.
   *
   * Pre-collapsing it here would defeat the guard; the operator must be
   * the one to see and refuse the escape.
   */
  public function testLeadingEscapeIsNotPreStripped(): void {
    self::assertSame('../escape.txt', $this->target('public://../escape.txt'));
  }

  /**
   * Backslashes are not translated by the wrapper.
   *
   * The library's normalizer owns backslash handling; doing it here would
   * place the same policy in two places and risk drift from the guard.
   */
  public function testBackslashesAreNotPreConverted(): void {
    self::assertSame('dir\\file.txt', $this->target('public://dir\\file.txt'));
  }

  /**
   * Only the scheme and erroneous edge slashes are trimmed, nothing more.
   */
  public function testOnlyEdgeSlashesTrimmed(): void {
    self::assertSame('a/b.txt', $this->target('public:///a/b.txt'));
    self::assertSame('a/b.txt', $this->target('public://a/b.txt/'));
    self::assertSame('a//b.txt', $this->target('public://a//b.txt'), 'an interior double slash belongs to the operator to resolve');
  }

  /**
   * The percent-encoded form is not decoded before the operator sees it.
   *
   * Decoding here would let a caller smuggle separators past the guard;
   * the raw encoded characters are handed down, and the guard runs on
   * whatever the caller literally wrote.
   */
  public function testPercentEncodingIsNotPreDecoded(): void {
    self::assertSame('a/%2e%2e/b', $this->target('public://a/%2e%2e/b'), 'a percent-encoded dot-dot is not turned into a real one at the seam');
  }

  /**
   * The parent-URI helper is scheme-correct and does not normalize depth.
   */
  public function testDirnameStaysWithinScheme(): void {
    $wrapper = new FlysystemPublicStream();
    self::assertSame('public://sub/deep', $wrapper->dirname('public://sub/deep/file.txt'), 'one level up, matching core\'s wrapper helper');
    self::assertSame('public://', $wrapper->dirname('public://file.txt'), 'a top-level file has the scheme as its directory');
  }

}
