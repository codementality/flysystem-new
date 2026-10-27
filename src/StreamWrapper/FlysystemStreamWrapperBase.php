<?php

declare(strict_types=1);

namespace Drupal\flysystem\StreamWrapper;

use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use League\Flysystem\FilesystemOperator;

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
    // memoized on this instance for the operations that follow; PHP's
    // protocol asks this method only for success or failure, and opening
    // bytes is the handle-semantics ticket's work.
    $this->resolveOperator();
    return FALSE;
  }

  /**
   * {@inheritdoc}
   *
   * Placeholder (no bytes read yet) — the handle-semantics row deepens
   * this method to the resolved operator.
   */
  public function stream_read($count) {
    return '';
  }

  /**
   * {@inheritdoc}
   *
   * Placeholder (no bytes written) — the handle-semantics row deepens
   * this method to the resolved operator.
   */
  public function stream_write($data) {
    return 0;
  }

  /**
   * {@inheritdoc}
   *
   * Placeholder (position zero) — deepened with the handle-semantics row.
   */
  public function stream_tell() {
    return 0;
  }

  /**
   * {@inheritdoc}
   *
   * Placeholder (immediate end of stream) — deepened with the
   * handle-semantics row.
   */
  public function stream_eof() {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_seek($offset, $whence = SEEK_SET) {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_flush() {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_truncate($new_size) {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_close() {
  }

  /**
   * {@inheritdoc}
   */
  public function stream_cast($cast_as) {
    return FALSE;
  }

  /**
   * {@inheritdoc}
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
   */
  public function stream_metadata($path, $option, $value) {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_stat() {
    return [];
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
