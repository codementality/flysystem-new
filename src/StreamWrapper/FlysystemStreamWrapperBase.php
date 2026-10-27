<?php

declare(strict_types=1);

namespace Drupal\flysystem\StreamWrapper;

use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;

// phpcs:disable Drupal.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
// The stream operation method names below (stream_open, dir_readdir,
// url_stat, and friends) are dictated by PHP's stream wrapper protocol:
// the engine calls them by exactly these names. Drupal core's own wrapper
// base class declares the same names and faces the same rule.

/**
 * Shared machinery for the Flysystem-backed storage wrappers.
 *
 * PHP instantiates stream wrappers itself, with no dependency injection,
 * so this class has a parameterless constructor and reaches the storage
 * factory lazily at the moment a file operation actually needs it. A
 * scheme whose binding is broken therefore behaves exactly as the
 * foundation promises: container build and install stay silent, and the
 * typed configuration error surfaces on first touch of that scheme.
 *
 * Storage operations are delegated to the filesystem operator built for
 * this wrapper's scheme, never to an adapter directly: routing through
 * the operator is what engages its path-traversal guard.
 *
 * Method ownership in this class family is deliberate:
 * - Scheme-identity behavior (type constants, names, descriptions,
 *   external URLs) differs per scheme, never per adapter, and is declared
 *   abstract here so each concrete wrapper must carry it.
 * - Byte-level storage behavior (open/read/write/seek, metadata,
 *   directory operations) is identical for every storage scheme and is
 *   implemented once in this class against the operator, deepened by the
 *   tickets that own each behavior. The placeholder return values below
 *   ("no bytes moved yet") belong to that staged build-out, not to any
 *   individual scheme: within the milestone the rows merge in sequence,
 *   and the binding's first end-to-end file operations are certified by
 *   the handle-semantics and metadata rows that deepen this class.
 */
abstract class FlysystemStreamWrapperBase implements StreamWrapperInterface {

  use StringTranslationTrait;

  /**
   * Stream context resource.
   *
   * PHP's stream engine assigns this property from outside the object the
   * moment a stream opens — a protected declaration fatal-errors under
   * fopen() ("Cannot access protected property"). Core's own wrappers
   * declare it public for exactly this reason; it is the engine's slot,
   * not application state, and nothing in this class reads it.
   *
   * @var resource|null
   *
   * @link https://www.php.net/manual/en/class.streamwrapper.php#streamwrapper.props.context
   */
  public $context;

  /**
   * The full URI currently set on this instance.
   *
   * @var string|null
   */
  protected $uri;

  /**
   * The operator for this wrapper's scheme, resolved on first use.
   *
   * @var \League\Flysystem\FilesystemOperator|null
   */
  protected ?FilesystemOperator $operator = NULL;

  /**
   * Whether operator resolution has already been attempted for this scheme.
   *
   * @var bool
   */
  protected bool $operatorResolved = FALSE;

  /**
   * The in-memory buffer behind the currently open handle.
   *
   * A handle reads whole-file content in once through the operator and
   * serves every later read/seek/tell/truncate from this buffer; a changed
   * buffer is handed back through the operator exactly once, on flush or
   * close. This is the buffer model that turns stateful PHP stream calls
   * into the stateless whole-file operator operations.
   *
   * @var resource|null
   */
  protected $buffer = NULL;

  /**
   * The operator path (target within the bound root) of the open handle.
   *
   * @var string|null
   */
  protected ?string $handlePath = NULL;

  /**
   * Whether the open handle allows writing.
   *
   * @var bool
   */
  protected bool $writable = FALSE;

  /**
   * Whether the buffer holds changes not yet handed back to the operator.
   *
   * @var bool
   */
  protected bool $dirty = FALSE;

  /**
   * Whether closing this handle must persist it, even with no bytes written.
   *
   * @var bool
   */
  protected bool $mustPersist = FALSE;

  /**
   * {@inheritdoc}
   */
  public function setUri($uri) {
    $this->uri = $uri;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getUri() {
    return $this->uri;
  }

  /**
   * Returns the target part of a URI for this wrapper's scheme.
   *
   * @param string|null $uri
   *   URI to be parsed. If omitted, the URI currently set is used.
   *
   * @return string
   *   The target (the part after "scheme://"), with erroneous leading or
   *   trailing slashes trimmed.
   */
  protected function getTarget($uri = NULL) {
    if (empty($uri)) {
      $uri = $this->getUri();
    }
    return StreamWrapperManager::getTarget($uri) ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function dirname($uri = NULL) {
    $uri = $uri ?: $this->getUri();
    [$scheme] = explode('://', $uri, 2);
    $target = $this->getTarget($uri);
    $dirname = dirname($target);

    if ($dirname == '.') {
      $dirname = '';
    }

    return $scheme . '://' . $dirname;
  }

  /**
   * Resolves the storage operator for this wrapper's scheme, once.
   *
   * Resolution happens at operation time, never earlier: the storage
   * factory validates the binding lazily and raises typed errors for the
   * touched scheme only. The memoized operator serves every later call
   * on this instance; PHP's protocol itself receives only success or
   * failure from the stream methods.
   *
   * @return \League\Flysystem\FilesystemOperator|null
   *   The operator, or NULL when this scheme resolves to core-native
   *   storage.
   *
   * @throws \Drupal\flysystem\Exception\FlysystemConfigExceptionInterface
   *   When the binding for this scheme is invalid; implementations let
   *   this propagate until the graceful-handling design lands.
   */
  protected function resolveOperator(): ?FilesystemOperator {
    if (!$this->operatorResolved) {
      $scheme = static::getSchemeName();
      /** @var \Drupal\flysystem\Factory\OperatorFactory $factory */
      $factory = \Drupal::service('flysystem.operator_factory');
      $this->operator = $factory->operatorFor($scheme);
      $this->operatorResolved = TRUE;
    }
    return $this->operator;
  }

  /**
   * The module's logger channel, for recording refused operations.
   *
   * @return \Psr\Log\LoggerInterface
   *   The channel service.
   */
  protected function logger(): LoggerInterface {
    /** @var \Psr\Log\LoggerInterface $logger */
    $logger = \Drupal::service('logger.channel.flysystem');
    return $logger;
  }

  /**
   * Runs one operator question, refusing softly when the storage layer says no.
   *
   * The guard and the backend answer with the library's own exceptions:
   * a rejected path, a refused write, a missing metadata answer. A path
   * is user input, not configuration — refusing it is normal operation,
   * and PHP's own file functions signal that outcome with a failed call,
   * not a crash. Typed binding errors (raised earlier, during
   * resolution) keep their contract and are never caught here.
   *
   * @param callable $ask
   *   The operator question, returning the answer on success.
   * @param callable $on_refusal
   *   Receives the exception; returns the value a refusal should answer.
   * @param string $level
   *   Severity for the record: 'warning' when nothing was lost, 'error'
   *   when data was at stake.
   *
   * @return mixed
   *   The answer, or the refusal value.
   */
  protected function guarded(callable $ask, callable $on_refusal, string $level = 'warning'): mixed {
    try {
      return $ask();
    }
    catch (FilesystemException $e) {
      $context = [
        'scheme' => static::getSchemeName(),
        'link' => NULL,
        'exception' => $e,
      ];
      $this->logger()->{$level}($e->getMessage(), $context);
      return $on_refusal($e);
    }
  }

  /**
   * The scheme this wrapper class serves, for operator resolution.
   *
   * @return string
   *   The scheme name, e.g. "public".
   */
  abstract public static function getSchemeName(): string;

  /**
   * {@inheritdoc}
   */
  abstract public function getName();

  /**
   * {@inheritdoc}
   */
  abstract public function getDescription();

  /**
   * {@inheritdoc}
   */
  abstract public function getExternalUrl();

  /**
   * {@inheritdoc}
   */
  public function realpath() {
    // Placeholder for the metadata ticket: canonical local paths are a
    // storage-location question handled once in this class against the
    // resolved operator (real for local storage, FALSE for stores with no
    // local path — the same answer every non-local core wrapper already
    // gives). Never a per-scheme difference.
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_open($path, $mode, $options, &$opened_path) {
    // Touching the scheme resolves its binding: a broken configuration
    // raises its typed error right here, which is what keeps the failure
    // lazy (build time stays silent) and scheme-local. The operator is
    // remembered on this instance for every call the handle makes after.
    $operator = $this->resolveOperator();
    if ($operator === NULL) {
      // The scheme answers core-native while this wrapper is registered
      // for it — a state the registration rules rule out. Refuse the open
      // rather than half-serve it.
      return FALSE;
    }

    $target = StreamWrapperManager::getTarget($path) ?? '';
    if ($target === '') {
      return FALSE;
    }
    $first = $mode[0] ?? 'r';
    $plus = str_contains($mode, '+');

    // Every question below is a storage-layer call that the guard or the
    // backend may refuse — a hostile or unservable path answers as a
    // failed open, recorded once, never a crash inside PHP's stream
    // layer. The typed binding contract is untouched: resolution already
    // happened above and still propagates.
    $failed = FALSE;
    $exists = $this->guarded(
      fn(): bool => $operator->fileExists($target),
      function () use (&$failed): bool {
        $failed = TRUE;
        return FALSE;
      },
    );
    if ($failed) {
      return FALSE;
    }

    if ($first === 'x' && $exists) {
      return FALSE;
    }
    if ($first === 'r' && !$exists) {
      return FALSE;
    }

    // The buffer model: whole-file content comes in once, through the
    // operator, at open. Write modes start from empty (truncation); a
    // 'w'/'x'/new-'a' handle must hand the file back on close even if
    // nothing is ever written, so an empty result still persists. When
    // existing content is required but cannot be pulled, the open fails
    // — proceeding would overwrite bytes it could not read.
    $contents = '';
    if ($exists && $first !== 'w') {
      $stream = $this->guarded(
        fn() => $operator->readStream($target),
        function () {
          return FALSE;
        },
        'error',
      );
      if ($stream === FALSE) {
        return FALSE;
      }
      $contents = (string) stream_get_contents($stream);
    }

    $this->buffer = fopen('php://memory', 'r+b');
    if ($this->buffer === FALSE) {
      return FALSE;
    }
    if ($contents !== '') {
      fwrite($this->buffer, $contents);
    }
    if ($first !== 'a') {
      rewind($this->buffer);
    }
    $this->handlePath = $target;
    $this->writable = $first !== 'r' || $plus;
    $this->dirty = FALSE;
    $this->mustPersist = $first === 'w' || $first === 'x' || !$exists;
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_read($count) {
    if ($this->buffer === NULL || $count <= 0) {
      return '';
    }
    return (string) fread($this->buffer, (int) $count);
  }

  /**
   * {@inheritdoc}
   */
  public function stream_write($data) {
    if ($this->buffer === NULL || !$this->writable) {
      return 0;
    }
    $written = fwrite($this->buffer, (string) $data);
    if ($written > 0) {
      $this->dirty = TRUE;
    }
    return (int) $written;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_tell() {
    return $this->buffer === NULL ? 0 : (int) ftell($this->buffer);
  }

  /**
   * {@inheritdoc}
   */
  public function stream_eof() {
    return $this->buffer === NULL ? TRUE : feof($this->buffer);
  }

  /**
   * {@inheritdoc}
   */
  public function stream_seek($offset, $whence = SEEK_SET) {
    if ($this->buffer === NULL) {
      return FALSE;
    }
    // A position before the start of the file must fail, not wrap — the
    // behavior a real file handle has.
    $moved = fseek($this->buffer, (int) $offset, $whence);
    return $moved === 0 && ftell($this->buffer) >= 0;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_flush() {
    return $this->persist();
  }

  /**
   * {@inheritdoc}
   */
  public function stream_truncate($new_size) {
    if ($this->buffer === NULL || !$this->writable) {
      return FALSE;
    }
    ftruncate($this->buffer, max(0, (int) $new_size));
    $this->dirty = TRUE;
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_close() {
    // PHP gives close no return channel, so the answer is not dropped:
    // persist() records a failed hand-back as a logged error before it
    // returns. Closing after a refusal that was already surfaced by
    // fflush() re-records only if bytes are still pending, which is the
    // point — nothing that was not written vanishes silently.
    $this->persist();
    if (is_resource($this->buffer)) {
      fclose($this->buffer);
    }
    $this->buffer = NULL;
    $this->handlePath = NULL;
    $this->writable = FALSE;
    $this->dirty = FALSE;
    $this->mustPersist = FALSE;
  }

  /**
   * Hands a changed handle back to the operator, exactly once per change.
   *
   * Flush and close both go through here; after a successful hand-back the
   * buffer is clean until the next change, so a handle that flushes twice
   * uploads once. The handle position is preserved across the hand-back,
   * which is what callers of fflush() expect on a real stream.
   */
  private function persist(): bool {
    if ($this->buffer === NULL || $this->handlePath === NULL) {
      return TRUE;
    }
    if (!$this->dirty && !$this->mustPersist) {
      return TRUE;
    }
    $operator = $this->resolveOperator();
    if ($operator === NULL) {
      return FALSE;
    }
    $position = ftell($this->buffer);
    rewind($this->buffer);
    // A refused hand-back must be answerable, not fatal: flush reports
    // the failure to its caller, and close — which PHP gives no failure
    // channel — records what was lost instead of swallowing it.
    $done = $this->guarded(
      function () use ($operator): bool {
        $operator->writeStream($this->handlePath, $this->buffer);
        return TRUE;
      },
      fn(): bool => FALSE,
      'error',
    );
    if (!$done) {
      fseek($this->buffer, (int) $position);
      return FALSE;
    }
    fseek($this->buffer, (int) $position);
    $this->dirty = FALSE;
    $this->mustPersist = FALSE;
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_cast($cast_as) {
    // The handle is a memory buffer; exposing it lets stream_select and
    // stream_copy_to_stream work on the read side, as on a real handle.
    return is_resource($this->buffer) ? $this->buffer : FALSE;
  }

  /**
   * {@inheritdoc}
   *
   * Documented deviation from a core local wrapper: advisory locks are
   * refused, not emulated. The buffer model holds no on-disk handle for
   * the life of the stream (content is pulled in and handed back
   * whole-file), and reaching past the operator for a real path would
   * bypass the traversal guard the binding depends on.
   */
  public function stream_lock($operation) {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_set_option($option, $arg1, $arg2) {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   *
   * Path-level attribute changes (touch, chmod, and friends) answer with
   * the metadata row's url_stat work; a handle refuses rather than
   * half-applies.
   */
  public function stream_metadata($path, $option, $value) {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_stat() {
    // State of the open handle itself; storage metadata answers live with
    // the url_stat work on the metadata row.
    return is_resource($this->buffer) ? (fstat($this->buffer) ?: []) : [];
  }

  /**
   * {@inheritdoc}
   */
  public function url_stat($path, $flags) {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function mkdir($path, $mode, $options) {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function rmdir($path, $options) {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function unlink($path) {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function rename($path_from, $path_to) {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function dir_opendir($path, $options) {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function dir_readdir() {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function dir_rewinddir() {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function dir_closedir() {
    return FALSE;
  }

}
