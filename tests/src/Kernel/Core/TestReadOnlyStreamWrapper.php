<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Kernel\Core;

use Drupal\Core\StreamWrapper\StreamWrapperInterface;

/**
 * A test-scope read-only posture over the subject stream wrapper.
 *
 * The read-only replay registers this class under a second scheme and
 * ports the write-rejection legs of Drupal core's file kernel test
 * corpus. Every refusal here mirrors the shape of core's own read-only
 * test wrapper (the read-only stream wrapper base in core's stream
 * wrapper API): write-mode stream opens, exclusive locks, writes,
 * truncation, metadata changes, renames, directory creation and
 * removal are refused — and unlink answers TRUE while leaving the
 * file in place, exactly the documented read-only posture. Read-mode
 * opens and shared locks delegate to the subject wrapper unchanged,
 * so read legs exercise the wrapper itself and, until its behavior
 * lands, go red naming its refusal. This class is a test fixture for
 * pinning that contract at the wrapper seam; it is not production
 * code.
 */
class TestReadOnlyStreamWrapper extends DispatchProbedStreamWrapper {

  /**
   * {@inheritdoc}
   */
  public static function getType(): int {
    // Read-only and local: no write bit, as core's read-only wrapper
    // declares.
    return StreamWrapperInterface::READ_VISIBLE | StreamWrapperInterface::LOCAL;
  }

  /**
   * {@inheritdoc}
   */
  // phpcs:ignore Drupal.NamingConventions.ValidFunctionName.ScopeNotCamelCaps -- The name is fixed by PHP's stream wrapper interface.
  public function stream_open($path, $mode, $options, &$opened_path): bool {
    if (!in_array($mode, ['r', 'rb', 'rt'])) {
      if ($options & STREAM_REPORT_ERRORS) {
        trigger_error('stream_open() write modes not supported for read-only stream wrappers', E_USER_WARNING);
      }
      static::record(__FUNCTION__);
      return FALSE;
    }
    return parent::stream_open($path, $mode, $options, $opened_path);
  }

  /**
   * {@inheritdoc}
   */
  // phpcs:ignore Drupal.NamingConventions.ValidFunctionName.ScopeNotCamelCaps -- The name is fixed by PHP's stream wrapper interface.
  public function stream_lock($operation): bool {
    static::record(__FUNCTION__);
    if (in_array($operation, [LOCK_EX, LOCK_EX | LOCK_NB])) {
      trigger_error('stream_lock() exclusive lock operations not supported for read-only stream wrappers', E_USER_WARNING);
      return FALSE;
    }
    if (in_array($operation, [LOCK_SH, LOCK_UN, LOCK_SH | LOCK_NB])) {
      return parent::stream_lock($operation);
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   *
   * Core's read-only wrapper returns FALSE here; the subject class
   * types this method's answer to int, so the refusal reports zero
   * bytes written — the same outcome every fwrite() caller sees,
   * which is what core's own leg asserts.
   */
  // phpcs:ignore Drupal.NamingConventions.ValidFunctionName.ScopeNotCamelCaps -- The name is fixed by PHP's stream wrapper interface.
  public function stream_write($data): int {
    trigger_error('stream_write() not supported for read-only stream wrappers', E_USER_WARNING);
    static::record(__FUNCTION__);
    return 0;
  }

  /**
   * {@inheritdoc}
   */
  // phpcs:ignore Drupal.NamingConventions.ValidFunctionName.ScopeNotCamelCaps -- The name is fixed by PHP's stream wrapper interface.
  public function stream_metadata($path, $option, $value): bool {
    trigger_error('stream_metadata() not supported for read-only stream wrappers', E_USER_WARNING);
    static::record(__FUNCTION__);
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  // phpcs:ignore Drupal.NamingConventions.ValidFunctionName.ScopeNotCamelCaps -- The name is fixed by PHP's stream wrapper interface.
  public function stream_truncate($new_size): bool {
    trigger_error('stream_truncate() not supported for read-only stream wrappers', E_USER_WARNING);
    static::record(__FUNCTION__);
    return FALSE;
  }

  /**
   * {@inheritdoc}
   *
   * Answers TRUE so a delete would drop stored metadata while the file
   * itself survives — the documented read-only posture, not a success
   * claim about removal.
   */
  public function unlink($path): bool {
    trigger_error('unlink() not supported for read-only stream wrappers', E_USER_WARNING);
    static::record(__FUNCTION__);
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function rename($path_from, $path_to): bool {
    trigger_error('rename() not supported for read-only stream wrappers', E_USER_WARNING);
    static::record(__FUNCTION__);
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function mkdir($path, $mode, $options): bool {
    trigger_error('mkdir() not supported for read-only stream wrappers', E_USER_WARNING);
    static::record(__FUNCTION__);
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function rmdir($path, $options): bool {
    trigger_error('rmdir() not supported for read-only stream wrappers', E_USER_WARNING);
    static::record(__FUNCTION__);
    return FALSE;
  }

}
