<?php

declare(strict_types=1);

namespace Drupal\flysystem\StreamWrapper;

use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\PhpStreamWrapperInterface;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use League\Flysystem\FilesystemOperator;

// The stream wrapper method names are dictated by the PHP engine's
// stream wrapper contract and cannot be renamed; only the naming
// sniff that cannot know that is stood down here.
// phpcs:disable Drupal.NamingConventions.ValidFunctionName
/**
 * The Flysystem stream wrapper.
 *
 * Every storage operation delegates to the bound
 * \League\Flysystem\FilesystemOperator — never to a raw adapter and
 * never to the local filesystem directly. The operator's configured
 * path-traversal guard is the containment wall; a wrapper that opened
 * disk paths itself would bypass the one component that enforces it.
 *
 * Handles are per-instance: PHP constructs a fresh wrapper for each
 * stream operation, and the open handle is an in-memory buffer
 * (php://temp) holding the current contents of the target object.
 * Writes land in the buffer immediately (so reads, seeks, and partial
 * rewrites behave like a physical file mid-handle) and persist to the
 * operator on flush and close. The stream-context property is the
 * engine's slot and is never repurposed; the handle has its own
 * property.
 *
 * Unix permission modes are a binary visibility question in Flysystem
 * ('public' or 'private' — the operator exposes no numeric chmod), so
 * stat answers report the mode the site's own permission settings
 * declare for that visibility: the same values the local adapter used
 * to create the file. This keeps fileperms()/is_writable answers
 * consistent with what the module's permission posture actually is;
 * arbitrary numeric chmods a caller might request are translated to
 * the nearest visibility, and the documented deviations register
 * carries what cannot be expressed.
 *
 * The production route to the operator is one seam:
 * operatorForPath() resolves the scheme of the path under operation
 * through the operator factory service — the platform's sanctioned
 * pattern for objects PHP's engine constructs outside the container,
 * the same one core's LocalStream uses to reach file_system. NULL
 * means the scheme resolves to core-native storage or carries no
 * scheme; the wrapper then refuses rather than acting on someone
 * else's storage.
 */
class FlysystemStreamWrapper implements PhpStreamWrapperInterface, StreamWrapperInterface {

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
   * The in-memory handle for the open stream, when this instance has one.
   *
   * @var resource|null
   */
  protected $handle = NULL;

  /**
   * Whether the open stream's mode permits writing.
   *
   * @var bool
   */
  protected bool $writable = FALSE;

  /**
   * Whether the open stream is in append position-on-write posture.
   *
   * @var bool
   */
  protected bool $appendMode = FALSE;

  /**
   * Whether the buffer holds changes not yet persisted to storage.
   *
   * @var bool
   */
  protected bool $dirty = FALSE;

  /**
   * The path this instance opened, as PHP passed it.
   *
   * The engine never calls setUri() on constructed wrapper instances,
   * so persistence reads the path captured at open time.
   *
   * @var string|null
   */
  protected ?string $openPath = NULL;

  /**
   * This instance's advisory lock state (flock bookkeeping).
   *
   * @var int
   */
  protected int $lock = LOCK_UN;

  /**
   * The listed directory entries for the open directory handle.
   *
   * @var string[]|null
   */
  protected ?array $dirEntries = NULL;

  /**
   * The read cursor into the directory entries.
   *
   * @var int
   */
  protected int $dirPosition = 0;

  /**
   * {@inheritdoc}
   */
  public function dir_closedir(): bool {
    $this->dirEntries = NULL;
    $this->dirPosition = 0;
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function dir_opendir($path, $options): bool {
    $operator = $this->operatorForPath((string) $path);
    if ($operator === NULL) {
      return FALSE;
    }
    $target = $this->targetFor((string) $path);
    if (!$this->targetIsDirectory($operator, $target)) {
      return FALSE;
    }
    $names = [];
    try {
      foreach ($operator->listContents($target, FALSE) as $attributes) {
        $names[] = $this->baseName($attributes->path());
      }
    }
    catch (\Throwable) {
      return FALSE;
    }
    // Dot entries come first, matching the enumeration core's own
    // wrappers and PHP's native directories produce.
    $this->dirEntries = $target === '' ? $names : array_merge(['.', '..'], $names);
    $this->dirPosition = 0;
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function dir_readdir(): false|string {
    if ($this->dirEntries === NULL || !array_key_exists($this->dirPosition, $this->dirEntries)) {
      return FALSE;
    }
    return $this->dirEntries[$this->dirPosition++];
  }

  /**
   * {@inheritdoc}
   */
  public function dir_rewinddir(): bool {
    $this->dirPosition = 0;
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function mkdir($path, $mode, $options): bool {
    $operator = $this->operatorForPath((string) $path);
    if ($operator === NULL) {
      return FALSE;
    }
    $target = $this->targetFor((string) $path);
    if ($target === '' || $this->targetIsDirectory($operator, $target)) {
      return FALSE;
    }
    // A non-recursive create requires the parent to exist already,
    // matching physical mkdir; the binding root is always its own
    // parent's answer.
    if (!($options & STREAM_MKDIR_RECURSIVE)) {
      $parent = $this->parentTarget($target);
      if ($parent !== '' && !$this->targetIsDirectory($operator, $parent)) {
        return FALSE;
      }
    }
    try {
      $operator->createDirectory($target);
      return TRUE;
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function rename($path_from, $path_to): bool {
    $scheme_from = StreamWrapperManager::getScheme((string) $path_from);
    $scheme_to = StreamWrapperManager::getScheme((string) $path_to);
    if ($scheme_from === FALSE || $scheme_to === FALSE || $scheme_from !== $scheme_to) {
      // Cross-scheme rename is not this operator's move. Answering
      // FALSE hands the caller (Drupal's file_system) its own
      // copy-then-delete fallback, which routes each half through the
      // correct storage.
      return FALSE;
    }
    $operator = $this->operatorForPath((string) $path_to);
    if ($operator === NULL) {
      return FALSE;
    }
    try {
      $operator->move($this->targetFor((string) $path_from), $this->targetFor((string) $path_to));
      return TRUE;
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function rmdir($path, $options): bool {
    $operator = $this->operatorForPath((string) $path);
    if ($operator === NULL) {
      return FALSE;
    }
    $target = rtrim($this->targetFor((string) $path), '/');
    if ($target === '' || !$this->targetIsDirectory($operator, $target)) {
      return FALSE;
    }
    try {
      if ($operator->listContents($target, FALSE)->hasNext()) {
        // Only an empty directory may be removed through this call;
        // a non-empty one is refused, matching the native contract.
        return FALSE;
      }
      $operator->deleteDirectory($target);
      return TRUE;
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   *
   * The buffer behind the open handle is a real stream resource, so
   * the engine can select() on it — the same posture core's own file
   * wrappers offer.
   */
  public function stream_cast($cast_as) {
    return $this->handle ?: FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_close(): void {
    if ($this->handle !== NULL) {
      if ($this->writable && $this->dirty) {
        $this->persist();
      }
      fclose($this->handle);
    }
    $this->handle = NULL;
    $this->dirty = FALSE;
    $this->writable = FALSE;
    $this->appendMode = FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_eof(): bool {
    return $this->handle === NULL ? TRUE : feof($this->handle);
  }

  /**
   * {@inheritdoc}
   */
  public function stream_flush(): bool {
    if ($this->handle === NULL) {
      return FALSE;
    }
    if (!$this->writable) {
      // A read-only handle has nothing to flush; the engine's fflush()
      // answer is FALSE, as it is for core's read-only wrappers.
      return FALSE;
    }
    if (!$this->dirty) {
      return TRUE;
    }
    return $this->persist();
  }

  /**
   * {@inheritdoc}
   */
  public function stream_lock($operation): bool {
    $operation = (int) $operation;
    $blocking = !($operation & LOCK_NB);
    $wanted = $operation & (LOCK_EX | LOCK_SH | LOCK_UN);
    if ($wanted === LOCK_UN) {
      $this->lock = LOCK_UN;
      return TRUE;
    }
    if ($this->lock !== LOCK_UN && $this->lock !== $wanted && !$blocking) {
      return FALSE;
    }
    $this->lock = $wanted === LOCK_EX ? LOCK_EX : LOCK_SH;
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_metadata($path, $option, $value): bool {
    $operator = $this->operatorForPath((string) $path);
    if ($operator === NULL) {
      return FALSE;
    }
    $target = $this->targetFor((string) $path);
    switch ($option) {

      case STREAM_META_TOUCH:
        try {
          if (!$operator->fileExists($target)) {
            $operator->write($target, '');
          }
          // Modification times cannot be set through the operator;
          // touching an existing object is answered as success
          // without a timestamp change (documented deviation).
          return TRUE;
        }
        catch (\Throwable) {
          return FALSE;
        }

      case STREAM_META_ACCESS:
        // Numeric permission modes are a binary visibility question
        // through the operator; group/other read access decides which
        // side, and stat answers then report the site's own declared
        // mode for that visibility.
        $visibility = ((int) $value & 0044) === 0044 ? 'public' : 'private';
        try {
          $operator->setVisibility($target, $visibility);
          return TRUE;
        }
        catch (\Throwable) {
          return FALSE;
        }

      case STREAM_META_OWNER:
      case STREAM_META_OWNER_NAME:
      case STREAM_META_GROUP:
      case STREAM_META_GROUP_NAME:
      default:
        return FALSE;

    }
  }

  /**
   * {@inheritdoc}
   */
  public function stream_open($path, $mode, $options, &$opened_path): bool {
    $operator = $this->operatorForPath((string) $path);
    if ($operator === NULL) {
      return FALSE;
    }
    $target = $this->targetFor((string) $path);
    $mode = (string) $mode;
    $truncate = FALSE;
    $create = FALSE;
    $this->writable = (bool) preg_match('/[\+wxca]/', $mode);
    $this->appendMode = str_starts_with($mode, 'a');
    $exclusive = str_starts_with($mode, 'x');
    $must_exist = str_starts_with($mode, 'r');
    if (str_starts_with($mode, 'w') || $exclusive) {
      $truncate = TRUE;
      $create = TRUE;
    }
    elseif (str_starts_with($mode, 'a') || str_starts_with($mode, 'c')) {
      $create = TRUE;
    }

    $exists = $operator->fileExists($target);
    if ($must_exist && !$exists) {
      return FALSE;
    }
    if ($exclusive && $exists) {
      return FALSE;
    }
    if ($create) {
      try {
        // 'w'/'x' truncate an existing object at open (the physical
        // contract); 'a'/'c' create only when nothing is there.
        if ($truncate || !$exists) {
          $operator->write($target, '');
        }
        $exists = TRUE;
      }
      catch (\Throwable) {
        return FALSE;
      }
    }

    $handle = fopen('php://temp', 'w+b');
    if ($handle === FALSE) {
      return FALSE;
    }
    if ($exists && !$truncate) {
      try {
        $contents = $operator->read($target);
      }
      catch (\Throwable) {
        fclose($handle);
        return FALSE;
      }
      fwrite($handle, $contents);
    }
    if ($this->appendMode) {
      fseek($handle, 0, SEEK_END);
    }
    else {
      rewind($handle);
    }
    $this->handle = $handle;
    $this->dirty = FALSE;
    $this->openPath = (string) $path;
    if ($options & STREAM_USE_PATH) {
      $opened_path = $target;
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_read($count): string|false {
    return $this->handle === NULL ? FALSE : fread($this->handle, (int) $count);
  }

  /**
   * {@inheritdoc}
   */
  public function stream_seek($offset, $whence = SEEK_SET): bool {
    return $this->handle !== NULL && fseek($this->handle, (int) $offset, $whence) === 0;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_set_option($option, $arg1, $arg2): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_stat(): array|false {
    if ($this->handle === NULL) {
      return FALSE;
    }
    $stat = fstat($this->handle);
    if ($stat === FALSE) {
      return FALSE;
    }
    $size = $this->bufferSize();
    $stat['size'] = $size;
    $stat[7] = $size;
    return $stat;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_tell(): int {
    return $this->handle === NULL ? -1 : (int) ftell($this->handle);
  }

  /**
   * {@inheritdoc}
   */
  public function stream_truncate($new_size): bool {
    if ($this->handle === NULL || !$this->writable) {
      return FALSE;
    }
    if (ftruncate($this->handle, (int) $new_size) === FALSE) {
      return FALSE;
    }
    $this->dirty = TRUE;
    return $this->persist();
  }

  /**
   * {@inheritdoc}
   */
  public function stream_write($data): int {
    if ($this->handle === NULL || !$this->writable) {
      return 0;
    }
    if ($this->appendMode) {
      fseek($this->handle, 0, SEEK_END);
    }
    $written = fwrite($this->handle, (string) $data);
    if ($written === FALSE) {
      return 0;
    }
    $this->dirty = TRUE;
    return $written;
  }

  /**
   * {@inheritdoc}
   */
  public function unlink($path): bool {
    $operator = $this->operatorForPath((string) $path);
    if ($operator === NULL) {
      return FALSE;
    }
    $target = $this->targetFor((string) $path);
    try {
      $operator->delete($target);
      return TRUE;
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function url_stat($path, $flags): array|false {
    $operator = $this->operatorForPath((string) $path);
    if ($operator === NULL) {
      return FALSE;
    }
    $target = $this->targetFor((string) $path);
    // The binding root is a property of the binding, not an object
    // inside it; the library's path grammar rejects the empty path,
    // so the root is answered before any operator query.
    if ($target === '') {
      return $this->statForDirectory($target);
    }
    try {
      if ($operator->fileExists($target)) {
        return $this->statForFile($operator, $target);
      }
      if ($this->targetIsDirectory($operator, $target)) {
        return $this->statForDirectory($target);
      }
    }
    catch (\Throwable) {
    }
    return FALSE;
  }

  /**
   * Persists the buffer of the open handle through the operator.
   *
   * @return bool
   *   TRUE when the whole buffer reached storage.
   */
  protected function persist(): bool {
    $path = $this->openPath ?? (string) $this->uri;
    $operator = $this->operatorForPath($path);
    if ($operator === NULL || $this->handle === NULL) {
      return FALSE;
    }
    $position = (int) ftell($this->handle);
    rewind($this->handle);
    $contents = stream_get_contents($this->handle);
    fseek($this->handle, $position);
    if ($contents === FALSE) {
      return FALSE;
    }
    try {
      $operator->write($this->targetFor($path), $contents);
      $this->dirty = FALSE;
      return TRUE;
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * The operator serving the scheme of the path under operation.
   *
   * The PHP engine builds wrapper instances without DI, so the
   * factory service is fetched lazily at the moment of need — all
   * binding resolution, validation, and memoization stay inside the
   * factory; the wrapper never touches adapters or configuration
   * directly. NULL means the scheme resolves to core-native storage
   * (unbound or disabled) or the path carries no scheme at all.
   *
   * @param string $path
   *   The full stream URI being operated on, as PHP passed it.
   *
   * @return \League\Flysystem\FilesystemOperator|null
   *   The bound operator, or NULL when the wrapper must stand aside.
   */
  protected function operatorForPath(string $path): ?FilesystemOperator {
    $scheme = StreamWrapperManager::getScheme($path);
    if ($scheme === FALSE) {
      return NULL;
    }
    /** @var \Drupal\flysystem\Factory\OperatorFactory $factory */
    $factory = \Drupal::service('flysystem.operator_factory');
    return $factory->operatorFor($scheme);
  }

  /**
   * The operator-root-relative target for a stream URI.
   *
   * @param string $path
   *   The full stream URI.
   *
   * @return string
   *   The target inside the bound root ('' for the root itself).
   */
  protected function targetFor(string $path): string {
    $scheme = StreamWrapperManager::getScheme($path);
    if ($scheme === FALSE) {
      return '';
    }
    $prefix = $scheme . '://';
    if (str_starts_with($path, $prefix)) {
      return ltrim(substr($path, strlen($prefix)), '/');
    }
    return ltrim($path, '/');
  }

  /**
   * Answers whether a target names a directory in the bound storage.
   *
   * The binding root is answered as an existing directory even while
   * the store has no rows yet: the root is a property of the binding,
   * not an object inside it — the same posture a mounted filesystem
   * presents, and the one core's own wrappers show.
   *
   * @param \League\Flysystem\FilesystemOperator $operator
   *   The bound operator.
   * @param string $target
   *   The normalized target path.
   *
   * @return bool
   *   TRUE when the target is (or is the root of) a directory.
   */
  protected function targetIsDirectory(FilesystemOperator $operator, string $target): bool {
    $trimmed = rtrim($target, '/');
    if ($trimmed === '') {
      return TRUE;
    }
    return $operator->directoryExists($trimmed);
  }

  /**
   * The parent target of a normalized target ('' at the root).
   *
   * @param string $target
   *   The normalized target.
   *
   * @return string
   *   The parent path inside the bound root.
   */
  protected function parentTarget(string $target): string {
    $position = strrpos($target, '/');
    return $position === FALSE ? '' : substr($target, 0, $position);
  }

  /**
   * The last path segment of an operator path.
   *
   * @param string $path
   *   A root-relative path.
   *
   * @return string
   *   The basename.
   */
  protected function baseName(string $path): string {
    $path = rtrim($path, '/');
    $position = strrpos($path, '/');
    return $position === FALSE ? $path : substr($path, $position + 1);
  }

  /**
   * Builds the complete stat array for an existing file object.
   *
   * @param \League\Flysystem\FilesystemOperator $operator
   *   The bound operator.
   * @param string $target
   *   The normalized target.
   *
   * @return array
   *   The PHP-shape stat array with no null members.
   */
  protected function statForFile(FilesystemOperator $operator, string $target): array {
    $size = $operator->fileSize($target);
    $mtime = 0;
    try {
      $mtime = $operator->lastModified($target);
    }
    catch (\Throwable) {
    }
    $visibility = 'private';
    try {
      $visibility = $operator->visibility($target);
    }
    catch (\Throwable) {
    }
    // Permission modes are read from the site's own settings, exactly
    // as the local adapter's visibility converter maps them at write
    // time — so a stat answer reports the mode the platform declared.
    $perms = $visibility === 'public'
      ? (int) Settings::get('file_chmod_file', 0664)
      : 0600;
    return $this->statArray(0100000 | $perms, $size, $mtime, 1);
  }

  /**
   * Builds the complete stat array for a directory (root or listed).
   *
   * @param string $target
   *   The normalized target ('' for the binding root).
   *
   * @return array
   *   The PHP-shape stat array with no null members.
   */
  protected function statForDirectory(string $target): array {
    // Directories land public (the write posture the module's create
    // paths use), so the answer is the site's declared directory mode
    // — the same source the local adapter's converter reads.
    $perms = (int) Settings::get('file_chmod_directory', 0775) & 0777;
    return $this->statArray(0040000 | $perms, 0, 0, 2);
  }

  /**
   * Assembles a complete PHP stat array with no null members.
   *
   * @param int $mode
   *   The S_IF* combined permission mode (literal constants; the
   *   S_IF* macros are not defined in this environment).
   * @param int $size
   *   The size in bytes.
   * @param int $mtime
   *   The modification timestamp.
   * @param int $nlink
   *   The link count (2 for directories).
   *
   * @return array
   *   The stat array.
   */
  protected function statArray(int $mode, int $size, int $mtime, int $nlink): array {
    return [
      'dev' => 0,
      'ino' => 0,
      'mode' => $mode,
      'nlink' => $nlink,
      'uid' => 0,
      'gid' => 0,
      'rdev' => -1,
      'size' => $size,
      'atime' => $mtime,
      'mtime' => $mtime,
      'ctime' => $mtime,
      'blksize' => 4096,
      'blocks' => (int) ceil($size / 512),
      0 => 0,
      1 => 0,
      2 => $mode,
      3 => $nlink,
      4 => 0,
      5 => 0,
      6 => -1,
      7 => $size,
      8 => $mtime,
      9 => $mtime,
      10 => $mtime,
      11 => 4096,
      12 => (int) ceil($size / 512),
    ];
  }

  /**
   * The current byte length of the open buffer.
   *
   * @return int
   *   The size in bytes.
   */
  protected function bufferSize(): int {
    if ($this->handle === NULL) {
      return 0;
    }
    $position = (int) ftell($this->handle);
    fseek($this->handle, 0, SEEK_END);
    $size = (int) ftell($this->handle);
    fseek($this->handle, $position);
    return $size;
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
    // The registration row decides the real type from the binding;
    // until then the wrapper reports a local read/write posture so
    // container wiring has an answer.
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
    $uri = (string) ($this->uri ?? $this->getUri());
    $target = $this->targetFor($uri);
    if ($target === '') {
      return '';
    }
    $segments = array_map('rawurlencode', explode('/', $target));
    $encoded = implode('/', $segments);
    // A served shape: fully qualified, like core's public wrapper's
    // answer, so generic consumers (the file URL generator) can
    // treat it as a location and not a bare path.
    if (!\Drupal::hasRequest()) {
      return '/' . $encoded;
    }
    $request = \Drupal::request();
    $base = $request->getSchemeAndHttpHost() . rtrim($request->getBaseUrl(), '/');
    $root = $this->rootPathFor($uri);
    $app_root = rtrim(\Drupal::root(), '/');
    if ($root !== NULL && str_starts_with($root, $app_root)) {
      // A binding rooted inside the web folder serves its files from
      // the web-relative root — exactly how core derives public URLs.
      $base .= '/' . trim(substr($root, strlen($app_root)), '/');
    }
    else {
      // Storage with no web-visible root still needs a stable,
      // scheme-local location; the scheme names that namespace.
      $base .= '/' . StreamWrapperManager::getScheme($uri);
    }
    return $base . '/' . $encoded;
  }

  /**
   * The absolute filesystem root of a local binding, when it has one.
   *
   * Metadata only — used for realpath()/URL answers; no content or
   * permission operation ever reads through it. The storage write and
   * read path stays the operator alone.
   *
   * @param string $uri
   *   The full stream URI.
   *
   * @return string|null
   *   The resolved root, or NULL when the binding is not local disk.
   */
  protected function rootPathFor(string $uri): ?string {
    $scheme = StreamWrapperManager::getScheme($uri);
    if ($scheme === FALSE) {
      return NULL;
    }
    /** @var \Drupal\flysystem\Factory\OperatorFactory $factory */
    $factory = \Drupal::service('flysystem.operator_factory');
    return $factory->localRootFor($scheme);
  }

  /**
   * {@inheritdoc}
   */
  public function realpath() {
    $uri = (string) ($this->uri ?? $this->getUri());
    $root = $this->rootPathFor($uri);
    if ($root === NULL) {
      return FALSE;
    }
    // Pure string composition: the answer is correct for objects
    // that do not exist yet, mirroring core's own wrapper.
    return rtrim($root, '/') . '/' . $this->targetFor($uri);
  }

  /**
   * {@inheritdoc}
   */
  public function dirname($uri = NULL) {
    $uri = $uri ?? $this->uri;
    if (!is_string($uri) || $uri === '') {
      return FALSE;
    }
    $scheme = StreamWrapperManager::getScheme($uri);
    if ($scheme === FALSE) {
      return FALSE;
    }
    $target = $this->targetFor($uri);
    $parent = $this->parentTarget($target);
    return $scheme . '://' . $parent;
  }

}
