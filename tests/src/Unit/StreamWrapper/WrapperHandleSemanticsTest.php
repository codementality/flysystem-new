<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Unit\StreamWrapper;

use Drupal\Core\Site\Settings;
use Drupal\flysystem\Exception\UnknownAdapterPluginException;
use Drupal\flysystem\Factory\OperatorFactory;
use Drupal\flysystem\StreamWrapper\FlysystemPublicStream;
use League\Flysystem\Config;
use League\Flysystem\PathTraversalDetected;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Tests wrapper handle semantics against a scripted operator double.
 *
 * The buffer model: on open the handle pulls whole-file content once
 * through the operator's readStream; reads and seeks serve from that
 * buffer; writes mark it dirty; flush/close hand the buffer back through
 * the operator's writeStream exactly once per changed handle. These units
 * pin that routing contract — every byte path goes through the operator
 * (never an adapter), with the mode semantics (append preserves, write
 * truncates, exclusive refuses, fresh files persist even when empty)
 * checked against the calls the operator double records. The end-to-end
 * replay against a real backend lives with the boot-tested parity suite.
 */
#[Group('flysystem')]
#[CoversNothing]
final class WrapperHandleSemanticsTest extends TestCase {

  /**
   * The in-memory backing store the operator double serves from.
   *
   * @var array<string, string>
   */
  private array $store = [];

  /**
   * Paths written through the operator double, in order.
   *
   * @var array<int, array{path: string, contents: string}>
   */
  private array $writes = [];

  /**
   * The operator double; the mock's invocation log is the routing proof.
   *
   * @var \League\Flysystem\FilesystemOperator|\PHPUnit\Framework\MockObject\MockObject
   */
  private $operator;

  /**
   * The factory double serving one memoized operator instance.
   *
   * @var \Drupal\flysystem\Factory\OperatorFactory|\PHPUnit\Framework\MockObject\MockObject
   */
  private $factory;

  /**
   * Logger channel double; failed hand-backs must leave a record.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  private $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->store = [];
    $this->writes = [];
    $this->logger = $this->createMock(LoggerInterface::class);

    $this->operator = $this->createMock(FilesystemOperator::class);
    $this->operator->method('fileExists')
      ->willReturnCallback(fn(string $path): bool => isset($this->store[$path]));
    $this->operator->method('readStream')
      ->willReturnCallback(function (string $path) {
        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, $this->store[$path] ?? '');
        rewind($stream);
        return $stream;
      });
    $this->operator->method('writeStream')
      ->willReturnCallback(function (string $path, $resource, array $config = []) {
        $this->writes[] = ['path' => $path, 'contents' => stream_get_contents($resource)];
        $this->store[$path] = $this->writes[array_key_last($this->writes)]['contents'];
        return new Config([]);
      });
    $this->operator->method('fileSize')
      ->willReturnCallback(fn(string $path): int => strlen($this->store[$path] ?? ''));

    $this->factory = $this->createMock(OperatorFactory::class);
    $this->factory->method('operatorFor')
      ->with('public')
      ->willReturn($this->operator);

    $container = $this->createMock(ContainerInterface::class);
    $container->method('get')->willReturnCallback(function (string $id) {
      if ($id === 'flysystem.operator_factory') {
        return $this->factory;
      }
      if ($id === 'logger.channel.flysystem') {
        return $this->logger;
      }
      return NULL;
    });
    \Drupal::setContainer($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    \Drupal::unsetContainer();
    new Settings([]);
    parent::tearDown();
  }

  /**
   * Opens a wrapper handle on the given path and mode.
   */
  private function open(string $path, string $mode) {
    $wrapper = new FlysystemPublicStream();
    $opened_path = NULL;
    $result = $wrapper->stream_open($path, $mode, STREAM_MUST_SEEK, $opened_path);
    self::assertNotFalse($result, "Opening $path in mode $mode must succeed.");
    return $wrapper;
  }

  /**
   * Reads go through the operator, never an adapter or direct file call.
   */
  public function testReadRoutesThroughOperatorRead(): void {
    $this->store['some/file.txt'] = 'stored content';

    $wrapper = $this->open('public://some/file.txt', 'r');
    self::assertSame('stored content', $wrapper->stream_read(64));
    self::assertTrue($wrapper->stream_eof());
  }

  /**
   * Changed handles are written back exactly once, on close.
   */
  public function testWriteRoutesThroughOperatorWriteOnceOnClose(): void {
    $wrapper = $this->open('public://fresh.txt', 'w');
    self::assertSame(5, $wrapper->stream_write('hello'));
    $wrapper->stream_close();

    self::assertCount(1, $this->writes);
    self::assertSame('fresh.txt', $this->writes[0]['path'], 'The operator path is the scheme target: the adapter root is the scheme boundary.');
    self::assertSame('hello', $this->writes[0]['contents']);
  }

  /**
   * Unchanged read-only handles never write back.
   */
  public function testReadOnlyHandleDoesNotWrite(): void {
    $this->store['only.txt'] = 'untouched';

    $wrapper = $this->open('public://only.txt', 'r');
    $wrapper->stream_read(3);
    $wrapper->stream_close();

    self::assertSame([], $this->writes);
  }

  /**
   * Truncation persists even when nothing is ever written.
   */
  public function testWriteModeOnExistingFileTruncatesEvenWithoutWrites(): void {
    $this->store['truncated.txt'] = 'old bytes';

    $wrapper = $this->open('public://truncated.txt', 'w');
    $wrapper->stream_close();

    self::assertSame('', $this->store['truncated.txt'], 'An existing file opened for write-only must be empty after close, as with a real filesystem.');
  }

  /**
   * Append opens at the end and preserves existing bytes.
   */
  public function testAppendPreservesAndAddsAtEnd(): void {
    $this->store['log.txt'] = 'first';

    $wrapper = $this->open('public://log.txt', 'a');
    self::assertSame(5, $wrapper->stream_tell(), 'Append positions at end of the existing content.');
    $wrapper->stream_write('-second');
    $wrapper->stream_close();

    self::assertSame('first-second', $this->store['log.txt']);
  }

  /**
   * Exclusive creation refuses to touch existing files.
   */
  public function testExclusiveCreateRefusesExisting(): void {
    $this->store['taken.txt'] = 'already here';

    $wrapper = new FlysystemPublicStream();
    $opened_path = NULL;
    self::assertFalse($wrapper->stream_open('public://taken.txt', 'x', 0, $opened_path));

    $wrapper2 = $this->open('public://brand-new.txt', 'x');
    $wrapper2->stream_write('new');
    $wrapper2->stream_close();
    self::assertSame('new', $this->store['brand-new.txt']);
  }

  /**
   * Seek boundaries behave like a real file position.
   */
  public function testSeekRespectsFileBoundaries(): void {
    $this->store['seek.txt'] = '0123456789';

    $wrapper = $this->open('public://seek.txt', 'r');
    self::assertTrue($wrapper->stream_seek(5));
    self::assertSame(5, $wrapper->stream_tell());
    self::assertTrue($wrapper->stream_seek(-2, SEEK_CUR));
    self::assertSame(3, $wrapper->stream_tell());
    self::assertTrue($wrapper->stream_seek(10));
    self::assertSame(10, $wrapper->stream_tell());
    self::assertTrue($wrapper->stream_seek(-10, SEEK_END));
    self::assertFalse($wrapper->stream_seek(-1), 'A negative position must fail, not wrap.');
  }

  /**
   * Mid-file partial rewrite keeps the untouched remainder.
   */
  public function testPartialRewriteKeepsSurroundingBytes(): void {
    $this->store['patch.txt'] = 'AAAAABBBBB';

    $wrapper = $this->open('public://patch.txt', 'r+');
    $wrapper->stream_seek(5);
    $wrapper->stream_write('XXXXX');
    $wrapper->stream_close();

    self::assertSame('AAAAAXXXXX', $this->store['patch.txt']);
  }

  /**
   * The operator is resolved when a handle opens, never per byte.
   *
   * Cross-handle memoization is the factory's job (proven in the factory
   * units); the wrapper must consult it once per open — not once per
   * read/write — and every handle of a scheme shares one service call.
   */
  public function testOperatorResolvedOncePerHandleNotPerOperation(): void {
    $this->factory->expects(self::exactly(2))->method('operatorFor')->with('public')->willReturn($this->operator);
    $this->store['b.txt'] = 'data';

    $a = $this->open('public://a.txt', 'w');
    $a->stream_write('x');
    $a->stream_read(0);
    $a->stream_close();
    $b = $this->open('public://b.txt', 'r');
    $b->stream_read(4);
    $b->stream_close();
  }

  /**
   * A broken binding refuses the open, recorded once, touching nothing.
   */
  public function testBrokenBindingRefusesAtOpenAndTouchesNothing(): void {
    $factory = $this->createMock(OperatorFactory::class);
    $factory->method('operatorFor')
      ->with('public')
      ->willThrowException(new UnknownAdapterPluginException('nosuchplugin', 'public'));
    $container = $this->createMock(ContainerInterface::class);
    $container->method('get')
      ->willReturnCallback(fn(string $id) => $id === 'flysystem.operator_factory' ? $factory : $this->logger);
    \Drupal::setContainer($container);

    $this->logger->expects(self::once())->method('error');
    $wrapper = new FlysystemPublicStream();
    $opened_path = NULL;
    self::assertFalse($wrapper->stream_open('public://whatever.txt', 'r', 0, $opened_path), 'The typed binding error answers as a failed open, never an escaping exception.');
  }

  /**
   * A failed hand-back answers FALSE on flush and leaves a record.
   *
   * Calling fflush() can report failure; the writeStream call may be refused
   * (the guard rejecting a hostile final path) or fail (the backend
   * declining the write). Either way the caller learns it and the log
   * carries why — a vanished buffer is never silent.
   */
  public function testFailedFlushReturnsFalseAndLogsOnce(): void {
    $operator = $this->createMock(FilesystemOperator::class);
    $operator->method('fileExists')->willReturn(FALSE);
    $operator->method('writeStream')
      ->willThrowException(UnableToWriteFile::atLocation('some/file.txt', 'disk full'));
    $this->factory = $this->createMock(OperatorFactory::class);
    $this->factory->method('operatorFor')->with('public')->willReturn($operator);
    $this->logger->expects(self::once())->method('error');
    $container = $this->createMock(ContainerInterface::class);
    $container->method('get')->willReturnCallback(fn(string $id) => $id === 'flysystem.operator_factory' ? $this->factory : $this->logger);
    \Drupal::setContainer($container);

    $wrapper = new FlysystemPublicStream();
    $opened_path = NULL;
    self::assertTrue($wrapper->stream_open('public://some/file.txt', 'w', 0, $opened_path));
    self::assertSame(3, $wrapper->stream_write('abc'));
    self::assertFalse($wrapper->stream_flush(), 'a refused hand-back answers as a failed operation');
  }

  /**
   * A close-time failure still records the loss.
   *
   * PHP's stream close has no failure channel — fclose() cannot report —
   * so a buffer that could not be handed back at close must leave a log
   * record instead of vanishing silently along with the data.
   */
  public function testCloseFailureLeavesRecord(): void {
    $operator = $this->createMock(FilesystemOperator::class);
    $operator->method('fileExists')->willReturn(FALSE);
    $operator->method('writeStream')
      ->willThrowException(UnableToWriteFile::atLocation('late.txt', 'nope'));
    $this->factory = $this->createMock(OperatorFactory::class);
    $this->factory->method('operatorFor')->with('public')->willReturn($operator);
    $this->logger->expects(self::once())->method('error');
    $container = $this->createMock(ContainerInterface::class);
    $container->method('get')->willReturnCallback(fn(string $id) => $id === 'flysystem.operator_factory' ? $this->factory : $this->logger);
    \Drupal::setContainer($container);

    $wrapper = new FlysystemPublicStream();
    $opened_path = NULL;
    $wrapper->stream_open('public://late.txt', 'w', 0, $opened_path);
    $wrapper->stream_write('lost');
    $wrapper->stream_close();
  }

  /**
   * A guard-refused path at open answers FALSE, never a fatal.
   *
   * The traversal guard throws on a hostile path; a path is user input,
   * not configuration, so a refusal at open fails the fopen the way a
   * missing file does — and records why. Typed binding errors (above)
   * keep their contract; this is the storage layer, where refusals live.
   */
  public function testOpenRefusesGuardRejectedPathAndLogs(): void {
    $operator = $this->createMock(FilesystemOperator::class);
    $operator->method('fileExists')
      ->willThrowException(PathTraversalDetected::forPath('../escape.txt'));
    $this->factory = $this->createMock(OperatorFactory::class);
    $this->factory->method('operatorFor')->with('public')->willReturn($operator);
    $this->logger->expects(self::once())->method('warning');
    $container = $this->createMock(ContainerInterface::class);
    $container->method('get')->willReturnCallback(fn(string $id) => $id === 'flysystem.operator_factory' ? $this->factory : $this->logger);
    \Drupal::setContainer($container);

    $wrapper = new FlysystemPublicStream();
    $opened_path = NULL;
    self::assertFalse($wrapper->stream_open('public://../escape.txt', 'r', 0, $opened_path), 'a refused path fails the open as a failed operation');
  }

}
