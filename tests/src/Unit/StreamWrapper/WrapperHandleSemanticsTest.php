<?php

declare(strict_types=1);

namespace Drupal\flysystem\Tests\Unit\StreamWrapper;

use Drupal\Core\Site\Settings;
use Drupal\flysystem\Exception\UnknownAdapterPluginException;
use Drupal\flysystem\Factory\OperatorFactory;
use Drupal\flysystem\StreamWrapper\FlysystemPublicStream;
use League\Flysystem\Config;
use League\Flysystem\FilesystemOperator;
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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->store = [];
    $this->writes = [];

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
    $container->method('get')
      ->with('flysystem.operator_factory')
      ->willReturn($this->factory);
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
   * A broken binding raises its typed error at open, touching nothing.
   */
  public function testBrokenBindingSurfacesAtOpenAndTouchesNothing(): void {
    $factory = $this->createMock(OperatorFactory::class);
    $factory->method('operatorFor')
      ->willThrowException(new UnknownAdapterPluginException('nosuchplugin', 'public'));
    $container = $this->createMock(ContainerInterface::class);
    $container->method('get')->with('flysystem.operator_factory')->willReturn($factory);
    \Drupal::setContainer($container);

    $this->expectException(UnknownAdapterPluginException::class);
    $wrapper = new FlysystemPublicStream();
    $opened_path = NULL;
    $wrapper->stream_open('public://whatever.txt', 'r', 0, $opened_path);
  }

}
