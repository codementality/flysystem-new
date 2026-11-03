<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Kernel\Core;

use Drupal\flysystem\StreamWrapper\FlysystemStreamWrapper;

// Engine contract names, inherited from the parent's PHP surface.
// phpcs:disable Drupal.NamingConventions.ValidFunctionName
/**
 * The harness's dispatch-probing wrapper; observability stays test-side.
 *
 * The replay suites' first assertion asks a question no later
 * assertion can answer for itself: did the PHP engine actually route
 * this scheme to a wrapper, or is everything below passing vacuously
 * against a scheme nobody serves? Answering it requires recording
 * engine calls — machinery that belongs to the harness, never to
 * shipped code. This class carries it: it records every engine call
 * in a static journal and delegates every refusal to the production
 * stub unchanged, so what the suites dispatch to and what ships are
 * deliberately one lineage.
 */
class DispatchProbedStreamWrapper extends FlysystemStreamWrapper {

  /**
   * Records engine calls (method name => count) since the last reset.
   *
   * @var array<string, int>
   */
  protected static array $journal = [];

  /**
   * Empties the dispatch journal.
   */
  public static function resetJournal(): void {
    static::$journal = [];
  }

  /**
   * Returns the dispatch journal (method name => times called).
   *
   * @return array<string, int>
   *   The journal.
   */
  public static function journal(): array {
    return static::$journal;
  }

  /**
   * Records one engine call.
   */
  protected static function record(string $method): void {
    static::$journal[$method] = (static::$journal[$method] ?? 0) + 1;
  }

  /**
   * {@inheritdoc}
   */
  public function dir_closedir(): bool {
    static::record(__FUNCTION__);
    return parent::dir_closedir();
  }

  /**
   * {@inheritdoc}
   */
  public function dir_opendir($path, $options): bool {
    static::record(__FUNCTION__);
    return parent::dir_opendir($path, $options);
  }

  /**
   * {@inheritdoc}
   */
  public function dir_readdir(): false|string {
    static::record(__FUNCTION__);
    return parent::dir_readdir();
  }

  /**
   * {@inheritdoc}
   */
  public function dir_rewinddir(): bool {
    static::record(__FUNCTION__);
    return parent::dir_rewinddir();
  }

  /**
   * {@inheritdoc}
   */
  public function mkdir($path, $mode, $options): bool {
    static::record(__FUNCTION__);
    return parent::mkdir($path, $mode, $options);
  }

  /**
   * {@inheritdoc}
   */
  public function rename($path_from, $path_to): bool {
    static::record(__FUNCTION__);
    return parent::rename($path_from, $path_to);
  }

  /**
   * {@inheritdoc}
   */
  public function rmdir($path, $options): bool {
    static::record(__FUNCTION__);
    return parent::rmdir($path, $options);
  }

  /**
   * {@inheritdoc}
   */
  public function stream_cast($cast_as) {
    static::record(__FUNCTION__);
    return parent::stream_cast($cast_as);
  }

  /**
   * {@inheritdoc}
   */
  public function stream_close(): void {
    static::record(__FUNCTION__);
    parent::stream_close();
  }

  /**
   * {@inheritdoc}
   */
  public function stream_eof(): bool {
    static::record(__FUNCTION__);
    return parent::stream_eof();
  }

  /**
   * {@inheritdoc}
   */
  public function stream_flush(): bool {
    static::record(__FUNCTION__);
    return parent::stream_flush();
  }

  /**
   * {@inheritdoc}
   */
  public function stream_lock($operation): bool {
    static::record(__FUNCTION__);
    return parent::stream_lock($operation);
  }

  /**
   * {@inheritdoc}
   */
  public function stream_metadata($path, $option, $value): bool {
    static::record(__FUNCTION__);
    return parent::stream_metadata($path, $option, $value);
  }

  /**
   * {@inheritdoc}
   */
  public function stream_open($path, $mode, $options, &$opened_path): bool {
    static::record(__FUNCTION__);
    return parent::stream_open($path, $mode, $options, $opened_path);
  }

  /**
   * {@inheritdoc}
   */
  public function stream_read($count): string|false {
    static::record(__FUNCTION__);
    return parent::stream_read($count);
  }

  /**
   * {@inheritdoc}
   */
  public function stream_seek($offset, $whence = SEEK_SET): bool {
    static::record(__FUNCTION__);
    return parent::stream_seek($offset, $whence);
  }

  /**
   * {@inheritdoc}
   */
  public function stream_set_option($option, $arg1, $arg2): bool {
    static::record(__FUNCTION__);
    return parent::stream_set_option($option, $arg1, $arg2);
  }

  /**
   * {@inheritdoc}
   */
  public function stream_stat(): array|false {
    static::record(__FUNCTION__);
    return parent::stream_stat();
  }

  /**
   * {@inheritdoc}
   */
  public function stream_tell(): int {
    static::record(__FUNCTION__);
    return parent::stream_tell();
  }

  /**
   * {@inheritdoc}
   */
  public function stream_truncate($new_size): bool {
    static::record(__FUNCTION__);
    return parent::stream_truncate($new_size);
  }

  /**
   * {@inheritdoc}
   */
  public function stream_write($data): int {
    static::record(__FUNCTION__);
    return parent::stream_write($data);
  }

  /**
   * {@inheritdoc}
   */
  public function unlink($path): bool {
    static::record(__FUNCTION__);
    return parent::unlink($path);
  }

  /**
   * {@inheritdoc}
   */
  public function url_stat($path, $flags): array|false {
    static::record(__FUNCTION__);
    return parent::url_stat($path, $flags);
  }

}
