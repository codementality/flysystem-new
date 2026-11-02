<?php

declare(strict_types=1);

namespace Drupal\flysystem\StreamWrapper;

use Drupal\Core\StreamWrapper\PhpStreamWrapperInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use League\Flysystem\FilesystemOperator;

// The stream wrapper method names are dictated by the PHP engine's
// stream wrapper contract and cannot be renamed; only the naming
// sniff that cannot know that is stood down here.
// phpcs:disable Drupal.NamingConventions.ValidFunctionName
/**
 * The flysystem stream wrapper: a documented stub, awaiting its spec.
 *
 * This class is the dispatch target for the replay suites in
 * tests/src/Kernel/Core — the copied core file-behavior corpus that
 * stands as the specification for storage behavior. Every
 * PHP-facing method answers with the engine's refusal shape: the class
 * is deliberately NOT functional yet, and each refusal exists so the
 * spec suite can run red for named, awaited behaviors instead of
 * failing on a missing seam. When a method's behavior lands, its
 * refusal is replaced by the specification's implementation — never
 * the other way around; no method ships a placeholder answer after
 * its suite has been amended.
 *
 * The production route to the operator is already here:
 * operatorForPath() resolves the scheme of the path under operation
 * through the operator factory service — the platform's sanctioned
 * pattern for objects PHP's engine constructs outside the container,
 * the same one core's LocalStream uses to reach file_system
 * (LocalStream.php:429). Until a method's spec'd behavior lands, the
 * methods refuse without calling it; when behavior lands, it calls
 * the seam that exists, not a seam invented later. No static state,
 * no test-only surface anywhere in this class.
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
   * {@inheritdoc}
   */
  public function dir_closedir(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function dir_opendir($path, $options): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function dir_readdir(): false|string {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function dir_rewinddir(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function mkdir($path, $mode, $options): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function rename($path_from, $path_to): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function rmdir($path, $options): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_cast($cast_as): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_close(): void {
  }

  /**
   * {@inheritdoc}
   */
  public function stream_eof(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_flush(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_lock($operation): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_metadata($path, $option, $value): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_open($path, $mode, $options, &$opened_path): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_read($count): string|false {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_seek($offset, $whence = SEEK_SET): bool {
    return FALSE;
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
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_tell(): int {
    return -1;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_truncate($new_size): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_write($data): int {
    return 0;
  }

  /**
   * {@inheritdoc}
   */
  public function unlink($path): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function url_stat($path, $flags): array|false {
    return FALSE;
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
    // the stub reports the local normal posture so container wiring
    // has an answer, and no replay leg asserts this value yet.
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
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function realpath() {
    // Identity answers are spec'd behaviors awaiting their turn.
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function dirname($uri = NULL) {
    return FALSE;
  }

}
