<?php

declare(strict_types=1);

// The stream wrapper method names are dictated by the PHP engine's
// stream wrapper contract and cannot be renamed; only the naming
// sniff that cannot know that is stood down here.
// phpcs:disable Drupal.NamingConventions.ValidFunctionName

namespace Drupal\flysystem\StreamWrapper;

use Drupal\Core\StreamWrapper\PhpStreamWrapperInterface;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use League\Flysystem\FilesystemOperator;

// The stream wrapper method names are dictated by the PHP engine's
// stream wrapper contract and cannot be renamed; only the naming
// sniff that cannot know that is stood down here.
// phpcs:disable Drupal.NamingConventions.ValidFunctionName

/**
 * The flysystem stream wrapper: a documented stub, awaiting its spec.
 *
 * This class is the dispatch target the replay suites register under a
 * test scheme, and the class the storage behavior suite (the copied
 * core touchpoint corpus in tests/src/Kernel/Core) is the standing
 * specification for. Every PHP-facing method answers with the engine's
 * refusal shape and records the call in a dispatch journal: the class
 * is deliberately NOT functional yet, and each refusal exists so the
 * spec suite can run red for named, awaited behaviors instead of
 * failing on a missing seam. When a method's behavior lands, its refusals are
 * replaced by the specification's implementation — never the other way
 * around; no method ships a placeholder answer after its suite has
 * been amended.
 *
 * The operator for the bound scheme arrives through the context slot
 * below, resolved lazily inside the first operation. This stub does
 * not read it yet; implementing methods do.
 */
class FlysystemStreamWrapper implements PhpStreamWrapperInterface, StreamWrapperInterface {

  /**
   * Records engine calls (method name => count) since the last reset.
   *
   * @var array<string, int>
   */
  protected static array $journal = [];

  /**
   * Builds the operator serving the replay scheme on first use.
   *
   * Installed by the harness; the wrapper resolves it lazily, exactly
   * as the production wrapper will resolve the factory's operator
   * inside the first stream operation (site settings are only readable
   * truthfully at that moment, not earlier).
   *
   * @var callable|null
   */
  protected static $operatorResolver = NULL;

  /**
   * The URI currently bound to this instance, when PHP gave us one.
   *
   * @var string|null
   */
  protected ?string $uri = NULL;

  /**
   * The stream context, set by the PHP engine itself.
   *
   * @var resource|null
   */
  public $context;

  /**
   * Installs the operator resolver for the bound scheme.
   */
  public static function setOperatorResolver(?callable $resolver): void {
    static::$operatorResolver = $resolver;
  }

  /**
   * Resolves the operator serving the bound scheme, lazily.
   */
  public static function resolveOperator(): ?FilesystemOperator {
    return static::$operatorResolver === NULL ? NULL : (static::$operatorResolver)();
  }

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
   * Records an engine call and hands back the refusal answer.
   */
  protected static function record(string $method): void {
    static::$journal[$method] = (static::$journal[$method] ?? 0) + 1;
  }

  /**
   * {@inheritdoc}
   */
  public function dir_closedir(): bool {
    static::record(__FUNCTION__);
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function dir_opendir($path, $options): bool {
    static::record(__FUNCTION__);
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function dir_readdir(): false|string {
    static::record(__FUNCTION__);
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function dir_rewinddir(): bool {
    static::record(__FUNCTION__);
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function mkdir($path, $mode, $options): bool {
    static::record(__FUNCTION__);
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function rename($path_from, $path_to): bool {
    static::record(__FUNCTION__);
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function rmdir($path, $options): bool {
    static::record(__FUNCTION__);
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_cast($cast_as): bool {
    static::record(__FUNCTION__);
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_close(): void {
    static::record(__FUNCTION__);
  }

  /**
   * {@inheritdoc}
   */
  public function stream_eof(): bool {
    static::record(__FUNCTION__);
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_flush(): bool {
    static::record(__FUNCTION__);
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_lock($operation): bool {
    static::record(__FUNCTION__);
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_metadata($path, $option, $value): bool {
    static::record(__FUNCTION__);
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_open($path, $mode, $options, &$opened_path): bool {
    static::record(__FUNCTION__);
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_read($count): string|false {
    static::record(__FUNCTION__);
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_seek($offset, $whence = SEEK_SET): bool {
    static::record(__FUNCTION__);
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_set_option($option, $arg1, $arg2): bool {
    static::record(__FUNCTION__);
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_stat(): array|false {
    static::record(__FUNCTION__);
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_tell(): int {
    static::record(__FUNCTION__);
    return -1;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_truncate($new_size): bool {
    static::record(__FUNCTION__);
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_write($data): int {
    static::record(__FUNCTION__);
    return 0;
  }

  /**
   * {@inheritdoc}
   */
  public function unlink($path): bool {
    static::record(__FUNCTION__);
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function url_stat($path, $flags): array|false {
    static::record(__FUNCTION__);
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getName(): string {
    return 'Flysystem';
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return 'Stores files through a Flysystem filesystem operator.';
  }

  /**
   * {@inheritdoc}
   */
  public static function getType(): int {
    // The registration row (and this suite's amendment) decides the
    // real type from the binding; the stub reports the local normal
    // posture so container wiring has an answer, and no replay leg
    // asserts this value yet.
    return StreamWrapperInterface::LOCAL_NORMAL;
  }

  /**
   * {@inheritdoc}
   */
  public function setUri($uri): void {
    $this->uri = $uri;
  }

  /**
   * {@inheritdoc}
   */
  public function getUri(): ?string {
    return $this->uri;
  }

  /**
   * {@inheritdoc}
   */
  public function getExternalUrl(): string {
    // URL generation is a spec'd behavior awaiting its implementation
    // turn; the stub refuses with the empty answer.
    static::record(__FUNCTION__);
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function realpath() {
    // Identity answers are spec'd behaviors awaiting their turn.
    static::record(__FUNCTION__);
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function dirname($uri = NULL) {
    static::record(__FUNCTION__);
    return FALSE;
  }

}
