<?php

declare(strict_types=1);

namespace Drupal\flysystem\StreamWrapper;

use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\flysystem\Exception\FlysystemConfigExceptionInterface;
use Drupal\flysystem\Exception\FlysystemPackageMissingException;
use Drupal\flysystem\Exception\FlysystemPluginNotFoundException;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Drupal\flysystem\Util\LexicalPath;
use League\Flysystem\Visibility;

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
 * binding error surfaces on first touch of that scheme — as a failed
 * operation with a logged record, never as an exception escaping into
 * PHP's stream layer, where it would abort the request over a file open.
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
   * Portable public file mode for stores without a unix mode concept.
   */
  private const FALLBACK_FILE_MODE = 0644;

  /**
   * Portable public directory mode for stores without a unix mode concept.
   */
  private const FALLBACK_DIR_MODE = 0755;

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
   * The seam's path contract: this strips ONLY the scheme and erroneous
   * edge slashes — it deliberately does not collapse '..' segments,
   * translate backslashes, or decode percent-escapes. Every one of those
   * decisions belongs to the operator's own normalizer, which runs before
   * the backend sees a path and is the single place the traversal guard
   * lives. Doing any of it here would put the same policy in two places
   * and let a raw hostile path (a leading '..' an attacker wrote
   * directly, a control character) slip past the guard to the storage
   * layer; passing the path down untouched is what keeps one wall.
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
   * touched scheme only. A binding error is a configuration defect, but
   * its consequence belongs to the file layer: PHP's stream protocol has
   * no failure channel beyond the return value, so every typed error the
   * factory declares is caught here and answered as no operator — the
   * same NULL an unbound scheme yields, which callers already refuse.
   * The refusal records one actionable error per wrapper instance
   * (attempts repeat the answer silently: same instance, same defect,
   * already said). The unavailable-backend error is the exception — the
   * factory records it itself before throwing, and the wrapper swallows
   * it without a second record for the one event.
   *
   * @return \League\Flysystem\FilesystemOperator|null
   *   The operator, or NULL when this scheme resolves to core-native
   *   storage or its binding is broken.
   */
  protected function resolveOperator(): ?FilesystemOperator {
    if (!$this->operatorResolved) {
      $scheme = static::getSchemeName();
      try {
        /** @var \Drupal\flysystem\Factory\OperatorFactory $factory */
        $factory = \Drupal::service('flysystem.operator_factory');
        $this->operator = $factory->operatorFor($scheme);
      }
      catch (FlysystemPackageMissingException) {
        // Recorded by the factory itself before it threw; recording it
        // again here would double-log the one failure.
      }
      catch (FlysystemConfigExceptionInterface | FlysystemPluginNotFoundException $e) {
        $this->logger()->error($e->getMessage(), [
          'scheme' => $scheme,
          'link' => NULL,
          'exception' => $e,
        ]);
      }
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
   * The resolved storage bundle for this scheme, or NULL if core-native.
   *
   * Carries the operator plus the facts metadata and path questions need
   * (plugin id, merged config, and whether the backend serves local
   * storage), all from one lazy resolution through the factory. Memoized
   * per instance the same way the operator is.
   *
   * @return array{operator: \League\Flysystem\FilesystemOperator, plugin: string, config: array, local: bool}|null
   *   The bundle, or NULL when the scheme resolves to core-native storage.
   */
  protected function storage(): ?array {
    if (!$this->storageResolved) {
      $scheme = static::getSchemeName();
      /** @var \Drupal\flysystem\Factory\OperatorFactory $factory */
      $factory = \Drupal::service('flysystem.operator_factory');
      $this->storageBundle = $factory->definitionFor($scheme);
      $this->storageResolved = TRUE;
      $this->operator = $this->storageBundle['operator'] ?? NULL;
    }
    return $this->storageBundle;
  }

  /**
   * Whether the storage bundle has been resolved for this instance.
   *
   * @var bool
   */
  protected bool $storageResolved = FALSE;

  /**
   * The memoized storage bundle.
   *
   * @var array<string, mixed>|null
   */
  protected ?array $storageBundle = NULL;

  /**
   * The unix permission bits a visibility answer maps to for this binding.
   *
   * A local binding reads its modes the way the local plugin applies them
   * on write — an explicit unix_visibility map wins, otherwise Drupal's
   * long-standing file_chmod settings. A store with no mode concept falls
   * back to the portable public bits. Public/private visibility is the
   * only permission model the storage abstraction expresses, so the
   * answer is a visibility-to-mode map, never a raw stat of a path.
   *
   * @param array<string, mixed> $storage
   *   The resolved storage bundle.
   * @param string $visibility
   *   The operator's visibility answer for the path.
   * @param bool $directory
   *   TRUE when answering for a directory.
   *
   * @return int
   *   The 0777 permission bits.
   */
  protected function modeBits(array $storage, string $visibility, bool $directory): int {
    $map = $storage['config']['unix_visibility'] ?? $storage['config']['permissions'] ?? NULL;
    if (is_array($map) && isset($map[$directory ? 'dir' : 'file'][$visibility])) {
      return (int) $map[$directory ? 'dir' : 'file'][$visibility];
    }
    if ($storage['local'] ?? FALSE) {
      if ($directory) {
        return $visibility === Visibility::PRIVATE ? 0700 : (int) Settings::get('file_chmod_directory', 0775);
      }
      return $visibility === Visibility::PRIVATE ? 0600 : (int) Settings::get('file_chmod_file', 0664);
    }
    return $directory ? self::FALLBACK_DIR_MODE : self::FALLBACK_FILE_MODE;
  }

  /**
   * Builds a complete PHP stat array from answered and synthesized fields.
   *
   * Every field is present and concrete; missing metadata answers fall to
   * documented defaults rather than nulls, which PHP's stat contract does
   * not tolerate. Fields Flysystem has no concept of (atime, ctime, the
   * inode-style extras) are filled from the answered mtime and process
   * identity. Both numeric and named keys are populated, matching a real
   * stat() call.
   *
   * @param int $typeBits
   *   The regular-file/directory type bits for the target.
   * @param int $size
   *   The byte count (zero for directories and unanswerable backends).
   * @param int $mtime
   *   The modification time, or zero when unknown.
   * @param int $mode
   *   The 0777 permission bits.
   *
   * @return array<int|string, int>
   *   The stat array.
   */
  protected function buildStat(int $typeBits, int $size, int $mtime, int $mode): array {
    $fullMode = $typeBits | $mode;
    $values = [
      'dev' => 0,
      'ino' => 0,
      'mode' => $fullMode,
      'nlink' => 1,
      'uid' => getmyuid(),
      'gid' => getmygid(),
      'rdev' => 0,
      'size' => $size,
      'atime' => $mtime,
      'mtime' => $mtime,
      'ctime' => $mtime,
      'blksize' => -1,
      'blocks' => -1,
    ];
    return array_merge(array_values($values), $values);
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
    // The canonical path question exists only where paths do: a local
    // binding answers root-joined-to-target, anchored and collapsed
    // lexically against the app root — never realpath()-ed, which would
    // chase symlinks and break on not-yet-created roots. A store with no
    // local filesystem answers FALSE, the established behavior of every
    // non-local wrapper.
    $storage = $this->storage();
    if ($storage === NULL || !$storage['local']) {
      return FALSE;
    }
    $root = $storage['config']['root'] ?? '';
    if (!is_string($root) || $root === '') {
      return FALSE;
    }
    $target = $this->getTarget();
    $appRoot = (string) \Drupal::getContainer()->getParameter('app.root');
    $resolved = LexicalPath::resolve(rtrim($root, '/') . '/' . $target, $appRoot);
    // The bound root is the traversal wall: a target that would collapse
    // above it answers as a failed operation, never a real path outside
    // the scheme's storage.
    return LexicalPath::isInside($resolved, LexicalPath::resolve($root, $appRoot)) ? $resolved : FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_open($path, $mode, $options, &$opened_path) {
    // Touching the scheme resolves its binding: a broken configuration
    // answers as no operator (recorded once, by resolveOperator()), which
    // is what keeps the failure lazy (build time stays silent) and
    // scheme-local, without letting a configuration defect become a
    // request-killing exception at the stream boundary. The operator is
    // remembered on this instance for every call the handle makes after.
    $operator = $this->resolveOperator();
    if ($operator === NULL) {
      // The scheme answers core-native while this wrapper is registered
      // for it, or its binding is broken — either way there is no
      // storage to serve from. Refuse the open rather than half-serve it.
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
    // layer. A broken binding never reaches this block: resolution
    // already refused above.
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
   * STREAM_META_TOUCH is honored through the whole-file contract: the
   * file is rewritten with its own content, which moves times forward on
   * local storage and creates an empty file when the path is missing.
   * The specific timestamps a caller passes cannot be applied — no
   * operator call sets them — so a requested mtime lands as the moment of
   * the write. Owner/group/access changes have no answer in the storage
   * abstraction at all and are refused, never half-applied.
   */
  public function stream_metadata($path, $option, $value) {
    if ($option !== STREAM_META_TOUCH) {
      return FALSE;
    }
    $storage = $this->storage();
    if ($storage === NULL) {
      return FALSE;
    }
    $operator = $storage['operator'];
    $target = StreamWrapperManager::getTarget($path) ?? '';
    if ($target === '') {
      return FALSE;
    }
    try {
      if ($operator->fileExists($target)) {
        $operator->write($target, $operator->read($target));
      }
      else {
        $operator->write($target, '');
      }
      return TRUE;
    }
    catch (FilesystemException) {
      return FALSE;
    }
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
   *
   * Every field is answered from a mapped operator question or a
   * documented default: size from the fileSize getter, times from
   * lastModified (atime/ctime, concepts Flysystem does not have, copy the
   * mtime), the type bits from the fileExists/directoryExists pair, mode
   * permission bits from visibility mapped through the binding's unix
   * mode settings. A field the backend cannot answer falls to zero, never
   * null — PHP's stat contract does not tolerate nulls, and three of the
   * library's getters pass nullable adapter answers straight through.
   * Failures of the guard itself (a path refused before the backend ever
   * saw it) answer the same way: a rejected stat is "no such file", not a
   * crash inside PHP's file functions.
   */
  public function url_stat($path, $flags) {
    $storage = $this->storage();
    if ($storage === NULL) {
      return FALSE;
    }
    $operator = $storage['operator'];
    $target = StreamWrapperManager::getTarget($path) ?? '';
    if ($target === '') {
      return FALSE;
    }

    try {
      if ($operator->fileExists($target)) {
        return $this->buildStat(
          0100000,
          $this->answered(fn(): int => $operator->fileSize($target), 0),
          $this->answered(fn(): int => $operator->lastModified($target), 0),
          $this->modeBits($storage, $this->answered(fn(): string => $operator->visibility($target), Visibility::PUBLIC), FALSE),
        );
      }
      if ($operator->directoryExists($target)) {
        return $this->buildStat(
          0040000,
          0,
          $this->answered(fn(): int => $operator->lastModified($target), 0),
          $this->modeBits($storage, $this->answered(fn(): string => $operator->visibility($target), Visibility::PUBLIC), TRUE),
        );
      }
    }
    catch (FilesystemException) {
      // Existence itself failed; treat as missing.
      return FALSE;
    }
    return FALSE;
  }

  /**
   * Asks one metadata question, falling back when the backend declines.
   *
   * @param callable $ask
   *   The operator call answering the field.
   * @param int|string $default
   *   The concrete fallback value.
   *
   * @return int|string
   *   The answer or the fallback.
   */
  private function answered(callable $ask, int|string $default): int|string {
    try {
      return $ask();
    }
    catch (FilesystemException) {
      return $default;
    }
  }

  /**
   * {@inheritdoc}
   *
   * The binding's configured visibility governs created directory modes —
   * the same rule the local plugin applies on writes — so a PHP mode
   * argument is not consulted; this is the documented mode-authority
   * deviation. Creation itself is the library's recursive mkdir.
   */
  public function mkdir($path, $mode, $options) {
    $storage = $this->storage();
    if ($storage === NULL) {
      return FALSE;
    }
    $target = StreamWrapperManager::getTarget($path) ?? '';
    if ($target === '') {
      return FALSE;
    }
    try {
      $storage['operator']->createDirectory($target);
      return TRUE;
    }
    catch (FilesystemException) {
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   *
   * A real filesystem refuses to remove a directory that still holds
   * files; the library's delete-by-prefix would silently wipe contents,
   * so the emptiness is checked first and a refusal never asks for the
   * removal at all.
   */
  public function rmdir($path, $options) {
    $storage = $this->storage();
    if ($storage === NULL) {
      return FALSE;
    }
    $operator = $storage['operator'];
    $target = StreamWrapperManager::getTarget($path) ?? '';
    if ($target === '') {
      return FALSE;
    }
    try {
      if (!$operator->directoryExists($target)) {
        return FALSE;
      }
      foreach ($operator->listContents($target, FALSE) as $ignored) {
        return FALSE;
      }
      $operator->deleteDirectory($target);
      return TRUE;
    }
    catch (FilesystemException) {
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function unlink($path) {
    $storage = $this->storage();
    if ($storage === NULL) {
      return FALSE;
    }
    $operator = $storage['operator'];
    $target = StreamWrapperManager::getTarget($path) ?? '';
    if ($target === '') {
      return FALSE;
    }
    try {
      if (!$operator->fileExists($target)) {
        return FALSE;
      }
      $operator->delete($target);
      return TRUE;
    }
    catch (FilesystemException) {
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   *
   * Same-operator rename is the adapter's native move — server-side, no
   * bytes pulled through userland, which is also what gives object stores
   * their copy-free relocation later.
   */
  public function rename($path_from, $path_to) {
    $storage = $this->storage();
    if ($storage === NULL) {
      return FALSE;
    }
    $operator = $storage['operator'];
    $from = StreamWrapperManager::getTarget($path_from) ?? '';
    $to = StreamWrapperManager::getTarget($path_to) ?? '';
    if ($from === '' || $to === '') {
      return FALSE;
    }
    try {
      if (!$operator->fileExists($from)) {
        return FALSE;
      }
      $operator->move($from, $to);
      return TRUE;
    }
    catch (FilesystemException) {
      return FALSE;
    }
  }

  /**
   * The open directory's entry list.
   *
   * Dot entries lead, matching what a real folder's readdir yields —
   * PHP injects nothing for user-space wrappers, so parity means
   * answering them here.
   *
   * @var string[]
   */
  protected array $dirEntries = [];

  /**
   * Read position within the open directory's entry list.
   *
   * @var int
   */
  protected int $dirPosition = 0;

  /**
   * {@inheritdoc}
   */
  public function dir_opendir($path, $options) {
    $storage = $this->storage();
    if ($storage === NULL) {
      return FALSE;
    }
    $operator = $storage['operator'];
    $target = StreamWrapperManager::getTarget($path) ?? '';
    if ($target === '') {
      return FALSE;
    }
    $names = [];
    try {
      if (!$operator->directoryExists($target)) {
        return FALSE;
      }
      foreach ($operator->listContents($target, FALSE) as $attribute) {
        $relative = $target === '' ? $attribute->path() : substr($attribute->path(), strlen($target) + 1);
        if ($relative !== '' && !str_contains($relative, '/')) {
          $names[] = $relative;
        }
      }
    }
    catch (FilesystemException) {
      return FALSE;
    }
    $this->dirEntries = array_merge(['.', '..'], $names);
    $this->dirPosition = 0;
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function dir_readdir() {
    return $this->dirEntries[$this->dirPosition++] ?? FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function dir_rewinddir() {
    $this->dirPosition = 0;
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function dir_closedir() {
    $this->dirEntries = [];
    $this->dirPosition = 0;
    return TRUE;
  }

}
