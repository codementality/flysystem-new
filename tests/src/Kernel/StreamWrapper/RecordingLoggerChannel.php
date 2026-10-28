<?php

declare(strict_types=1);

namespace Drupal\flysystem\Tests\Kernel\StreamWrapper;

use Psr\Log\LoggerInterface;

/**
 * An in-memory logger channel that records everything sent to it.
 *
 * Kernel tests swap this into the container in place of the module's
 * logger channel to prove that a refused file operation leaves exactly one
 * record, with the level and context the operation should carry.
 */
final class RecordingLoggerChannel implements LoggerInterface {

  /**
   * Every record received, in order.
   *
   * @var array<int, array{level: mixed, message: string, context: array}>
   */
  public array $records = [];

  /**
   * {@inheritdoc}
   */
  public function log($level, string|\Stringable $message, array $context = []): void {
    $this->records[] = [
      'level' => $level,
      'message' => (string) $message,
      'context' => $context,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function emergency(string|\Stringable $message, array $context = []): void {
    $this->log('emergency', $message, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function alert(string|\Stringable $message, array $context = []): void {
    $this->log('alert', $message, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function critical(string|\Stringable $message, array $context = []): void {
    $this->log('critical', $message, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function error(string|\Stringable $message, array $context = []): void {
    $this->log('error', $message, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function warning(string|\Stringable $message, array $context = []): void {
    $this->log('warning', $message, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function notice(string|\Stringable $message, array $context = []): void {
    $this->log('notice', $message, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function info(string|\Stringable $message, array $context = []): void {
    $this->log('info', $message, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function debug(string|\Stringable $message, array $context = []): void {
    $this->log('debug', $message, $context);
  }

}
