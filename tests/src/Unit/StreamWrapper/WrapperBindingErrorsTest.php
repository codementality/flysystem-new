<?php

declare(strict_types=1);

namespace Drupal\flysystem\Tests\Unit\StreamWrapper;

use League\Flysystem\FilesystemOperator;
use Drupal\flysystem\Exception\FlysystemPackageMissingException;
use Drupal\flysystem\Exception\FlysystemPluginNotFoundException;
use Drupal\flysystem\Exception\InvalidAdapterIdException;
use Drupal\flysystem\Exception\InvalidSchemeNameException;
use Drupal\flysystem\Exception\InvalidSettingsStructureException;
use Drupal\flysystem\Exception\PublicRootOutsideWebrootException;
use Drupal\flysystem\Exception\UnknownAdapterDefinitionException;
use Drupal\flysystem\Exception\UnknownAdapterPluginException;
use Drupal\flysystem\Factory\OperatorFactory;
use Drupal\flysystem\StreamWrapper\FlysystemPublicStream;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Tests that broken bindings refuse operations instead of crashing.
 *
 * A misconfigured scheme is discovered only when a file operation touches
 * it; the storage seam then answers with a typed error naming the defect.
 * PHP's stream protocol has no failure channel beyond the return value,
 * and an exception escaping a wrapper method would abort the request over
 * a file open. These units pin the wrapper's posture: every typed binding
 * error the storage seam declares answers the open as a refusal, logged
 * once through the module's own channel at error severity — with one
 * exception: the unavailable-backend failure is already recorded by the
 * storage seam itself before it throws, so the wrapper must not record it
 * a second time for the same event. The broken scheme is also consulted
 * only once per handle attempt: a refused resolution never re-runs the
 * build on later calls from the same wrapper instance.
 */
#[Group('flysystem')]
#[CoversNothing]
final class WrapperBindingErrorsTest extends TestCase {

  /**
   * The factory double whose answer is scripted per test.
   *
   * @var \Drupal\flysystem\Factory\OperatorFactory|\PHPUnit\Framework\MockObject\MockObject
   */
  private $factory;

  /**
   * The logger channel double; refusals must leave exactly one record.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  private $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->factory = $this->createMock(OperatorFactory::class);
    $this->logger = $this->createMock(LoggerInterface::class);
    $container = $this->createMock(ContainerInterface::class);
    $container->method('get')
      ->willReturnCallback(fn(string $id) => match ($id) {
        'flysystem.operator_factory' => $this->factory,
        'logger.channel.flysystem' => $this->logger,
        default => NULL,
      });
    \Drupal::setContainer($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * Opens a file through a fresh wrapper instance on the bound scheme.
   *
   * @return bool
   *   The open's answer as PHP would receive it.
   */
  private function open(): bool {
    $opened_path = NULL;
    return (new FlysystemPublicStream())->stream_open('public://x.txt', 'r', 0, $opened_path);
  }

  /**
   * Points the factory at a typed binding error for every resolution.
   *
   * @param \Throwable $exception
   *   The error the storage seam raises when the scheme is touched.
   */
  private function bindBroken(\Throwable $exception): void {
    $this->factory->method('operatorFor')
      ->with('public')
      ->willThrowException($exception);
  }

  /**
   * Every typed binding error refuses the open instead of escaping.
   *
   * @param \Throwable $exception
   *   The binding error raised at resolution.
   * @param bool $expectsRecord
   *   Whether the wrapper itself must record the refusal; the
   *   unavailable-backend error is recorded by the storage seam already.
   */
  #[DataProvider('typedBindingErrors')]
  public function testTypedBindingErrorRefusesOpenAndRecordsOnce(\Throwable $exception, bool $expectsRecord): void {
    $this->bindBroken($exception);
    if ($expectsRecord) {
      $this->logger->expects(self::once())->method('error')->with(
        self::identicalTo($exception->getMessage()),
        self::callback(static function ($context) use ($exception): bool {
          return ($context['scheme'] ?? NULL) === 'public'
            && ($context['exception'] ?? NULL) === $exception;
        }),
      );
    }
    else {
      $this->logger->expects(self::never())->method('error');
    }
    self::assertFalse($this->open(), 'A broken binding must fail the open, never crash it.');
  }

  /**
   * The scheme is resolved once per attempt, not once per operation.
   *
   * After a refused open the instance remembers the binding is broken:
   * later calls refuse from the memo without re-entering the storage
   * seam and without adding records.
   */
  public function testBrokenBindingIsConsultedOncePerInstance(): void {
    $this->bindBroken(new UnknownAdapterPluginException('nosuchplugin', 'public'));
    $this->logger->expects(self::once())->method('error');
    $this->factory->expects(self::exactly(1))->method('operatorFor')->with('public');
    $wrapper = new FlysystemPublicStream();
    $opened_path = NULL;
    self::assertFalse($wrapper->stream_open('public://x.txt', 'r', 0, $opened_path));
    self::assertFalse($wrapper->stream_open('public://y.txt', 'w', 0, $opened_path));
  }

  /**
   * A refused open leaves no half-built handle behind.
   *
   * The buffer-model guards answer the empty-handle values PHP expects:
   * writes write nothing, reads read nothing, and a fresh open attempt
   * still re-runs resolution because the previous instance is done.
   */
  public function testRefusedOpenLeavesNoUsableHandle(): void {
    $this->bindBroken(new FlysystemPluginNotFoundException('sftp'));
    $wrapper = new FlysystemPublicStream();
    $opened_path = NULL;
    self::assertFalse($wrapper->stream_open('public://x.txt', 'w', 0, $opened_path));
    self::assertSame(0, $wrapper->stream_write('data'));
    self::assertSame('', $wrapper->stream_read(4));
    self::assertTrue($wrapper->stream_eof());
    $wrapper->stream_close();
  }

  /**
   * An unbound scheme answers core-native: refused open, no record.
   *
   * The registration rules keep this wrapper off unbound schemes, so a
   * NULL operator here is a defensive state — it must still refuse
   * quietly rather than crash or claim a problem.
   */
  public function testCoreNativeAnswerRefusesOpenWithNoRecord(): void {
    $this->factory->method('operatorFor')->with('public')->willReturn(NULL);
    $this->logger->expects(self::never())->method('error');
    self::assertFalse($this->open());
  }

  /**
   * A healthy binding records nothing while it serves.
   *
   * Refusal records are for refusals: a working scheme stays silent even
   * though the read path runs through the same guards.
   */
  public function testHealthyOpenLeavesNoRecord(): void {
    $operator = $this->createMock(FilesystemOperator::class);
    $operator->method('fileExists')->willReturn(TRUE);
    $stream = fopen('php://memory', 'r+b');
    fwrite($stream, 'content');
    rewind($stream);
    $operator->method('readStream')->willReturn($stream);
    $this->factory->method('operatorFor')->with('public')->willReturn($operator);
    $this->logger->expects(self::never())->method('error');
    self::assertTrue($this->open());
  }

  /**
   * One data set per typed error the storage seam declares.
   *
   * Each carries the record expectation the wrapper owes it: everything
   * raised before the seam's own record excepts the unavailable-backend
   * error, which the seam writes itself.
   *
   * @return array<string, array{0: \Throwable, 1: bool}>
   *   Named data sets of exception and expected-wrapper-record flag.
   */
  public static function typedBindingErrors(): array {
    return [
      'unknown adapter plugin' => [new UnknownAdapterPluginException('nosuchplugin', 'public'), TRUE],
      'undefined adapter definition' => [new UnknownAdapterDefinitionException('missingid', 'public'), TRUE],
      'invalid adapter id' => [new InvalidAdapterIdException('Bad-Id'), TRUE],
      'invalid scheme name' => [new InvalidSchemeNameException('Not valid'), TRUE],
      'malformed settings structure' => [
        new InvalidSettingsStructureException('flysystem.public', 'an array with driver and config'),
        TRUE,
      ],
      'public root outside webroot' => [
        new PublicRootOutsideWebrootException('public', '/etc/passwd', '/var/www/html/web'),
        TRUE,
      ],
      'plugin not found' => [new FlysystemPluginNotFoundException('sftp'), TRUE],
      'backend package unavailable' => [new FlysystemPackageMissingException('sftp'), FALSE],
    ];
  }

}
