<?php

declare(strict_types=1);

namespace Drupal\flysystem\Tests\Unit\StreamWrapper;

use League\Flysystem\DirectoryListing;
use League\Flysystem\DirectoryAttributes;
use Drupal\Core\Site\Settings;
use Drupal\flysystem\Exception\FlysystemPackageMissingException;
use Drupal\flysystem\Exception\UnknownAdapterPluginException;
use Drupal\flysystem\Factory\OperatorFactory;
use Drupal\flysystem\StreamWrapper\FlysystemPublicStream;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToRetrieveMetadata;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Tests metadata and directory answers routed through the operator.
 *
 * The units drive the wrapper's public surface directly against a
 * recording operator double, so every PHP-visible file_* and directory
 * function is proven to ask exactly the mapped operator questions —
 * fileSize for size, lastModified for times, the fileExists/directoryExists
 * pair for type, visibility mapped through the binding's unix mode map
 * for permission bits — and never anything else. The proof-carrying part
 * is the call log: a path that reached a raw adapter or the filesystem
 * directly (which would bypass the traversal guard and the binding's
 * boundary) shows here as the wrong method being asked.
 *
 * The synthesis contract: no null field ever reaches PHP's stat array.
 * Flysystem's attributes are nullable by signature and three of its four
 * metadata getters delegate the null through, so every field is answered
 * independently: a backend that reports no size still produces a complete
 * row, with the documented defaults.
 */
#[Group('flysystem')]
#[CoversNothing]
final class WrapperMetadataOperationsTest extends TestCase {

  /**
   * Operator methods called, in order.
   *
   * @var string[]
   */
  private array $calls = [];

  /**
   * File fixtures keyed by operator path.
   *
   * @var array<string, array{size: int, mtime: int, visibility: string}>
   */
  private array $files = [];

  /**
   * Existing directory paths.
   *
   * @var string[]
   */
  private array $dirs = [];

  /**
   * Children per directory: name => is-directory.
   *
   * @var array<string, array<string, bool>>
   */
  private array $children = [];

  /**
   * The binding definition the factory hands out.
   *
   * @var array<string, mixed>|null
   */
  private ?array $definition = NULL;

  /**
   * Whether the factory answers a core-native resolution.
   */
  private bool $unbound = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->calls = [];
    $this->files = [];
    $this->dirs = [];
    $this->children = [];
    $this->unbound = FALSE;
    $this->definition = ['plugin' => 'local', 'config' => [], 'local' => TRUE];
    new Settings(['file_chmod_file' => 0664, 'file_chmod_directory' => 0775]);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    new Settings([]);
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * Wires a recording operator and definition into a fresh wrapper.
   */
  private function wrapper(): FlysystemPublicStream {
    return $this->wrapperWith($this->recordingOperator());
  }

  /**
   * Wires a caller-built operator into a fresh wrapper.
   */
  private function wrapperWith(FilesystemOperator $operator): FlysystemPublicStream {
    $factory = $this->createMock(OperatorFactory::class);
    // definitionFor answers the same bundle the real factory builds: the
    // operator plus locality/config the metadata questions consume. The
    // call log records that the wrapper asked the factory, not the
    // resolver or a raw adapter.
    $factory->method('definitionFor')->willReturnCallback(
      function (string $scheme) use ($operator): ?array {
        $this->calls[] = 'definitionFor';
        if ($this->unbound) {
          return NULL;
        }
        return [
          'operator' => $operator,
          'plugin' => $this->definition['plugin'],
          'config' => $this->definition['config'],
          'local' => $this->definition['local'],
        ];
      },
    );
    $container = $this->createMock(ContainerInterface::class);
    $container->method('get')->with('flysystem.operator_factory')->willReturn($factory);
    $container->method('getParameter')->with('app.root')->willReturn('/srv/app');
    \Drupal::setContainer($container);
    return new FlysystemPublicStream();
  }

  /**
   * The operator double, recording and answering from fixtures.
   */
  private function recordingOperator(): FilesystemOperator {
    $operator = $this->createMock(FilesystemOperator::class);
    $ask = function (string $name): void {
      $this->calls[] = $name;
    };
    $operator->method('fileExists')->willReturnCallback(
      function (string $path) use ($ask): bool {
        $ask('fileExists');
        return isset($this->files[$path]);
      },
    );
    $operator->method('directoryExists')->willReturnCallback(
      function (string $path) use ($ask): bool {
        $ask('directoryExists');
        return in_array($path, $this->dirs, TRUE);
      },
    );
    $operator->method('fileSize')->willReturnCallback(
      function (string $path) use ($ask): int {
        $ask('fileSize');
        if (!isset($this->files[$path])) {
          throw UnableToRetrieveMetadata::fileSize($path, 'missing');
        }
        return $this->files[$path]['size'];
      },
    );
    $operator->method('lastModified')->willReturnCallback(
      function (string $path) use ($ask): int {
        $ask('lastModified');
        if (!isset($this->files[$path])) {
          throw UnableToRetrieveMetadata::lastModified($path, 'missing');
        }
        return $this->files[$path]['mtime'];
      },
    );
    $operator->method('visibility')->willReturnCallback(
      function (string $path) use ($ask): string {
        $ask('visibility');
        if (!isset($this->files[$path])) {
          throw UnableToRetrieveMetadata::visibility($path, 'missing');
        }
        return $this->files[$path]['visibility'];
      },
    );
    $operator->method('listContents')->willReturnCallback(
      function (string $prefix, bool $deep = FALSE) use ($ask) {
        $ask('listContents');
        $entries = [];
        foreach ($this->children[$prefix] ?? [] as $name => $isDir) {
          // A real adapter lists paths relative to the bound ROOT, not
          // to the queried prefix: shelf/a.txt, not a.txt.
          $listed = $prefix === '' ? $name : $prefix . '/' . $name;
          $entries[] = $isDir
            ? new DirectoryAttributes($listed)
            : new FileAttributes($listed);
        }
        return new DirectoryListing($entries);
      },
    );
    $operator->method('createDirectory')->willReturnCallback(
      function (string $path) use ($ask): void {
        $ask('createDirectory');
        if ($path === 'denied') {
          throw UnableToCreateDirectory::atLocation($path, 'no');
        }
      },
    );
    $operator->method('delete')->willReturnCallback(
      function (string $path) use ($ask): void {
        $ask('delete');
        if (!isset($this->files[$path])) {
          throw UnableToDeleteFile::atLocation($path, 'missing');
        }
        unset($this->files[$path]);
      },
    );
    $operator->method('deleteDirectory')->willReturnCallback(
      function (string $path) use ($ask): void {
        $ask('deleteDirectory');
      },
    );
    $operator->method('move')->willReturnCallback(
      function (string $from, string $to) use ($ask): void {
        $ask('move');
        if (!isset($this->files[$from])) {
          throw UnableToMoveFile::fromLocationTo($from, $to);
        }
        $this->files[$to] = $this->files[$from];
        unset($this->files[$from]);
      },
    );
    $operator->method('read')->willReturnCallback(
      function (string $path) use ($ask): string {
        $ask('read');
        return $this->files[$path]['contents'] ?? '';
      },
    );
    $operator->method('write')->willReturnCallback(
      function (string $path, string $contents) use ($ask): void {
        $ask('write');
        $this->files[$path] = ($this->files[$path] ?? []) + ['size' => 0, 'mtime' => 1, 'visibility' => 'public'];
        $this->files[$path]['contents'] = $contents;
      },
    );
    return $operator;
  }

  /**
   * A file's stat row carries the mapped fields with no nulls.
   */
  public function testFileStatRowIsComplete(): void {
    $this->files['report.txt'] = ['size' => 42, 'mtime' => 1700000000, 'visibility' => 'public', 'contents' => 'x'];

    $stat = $this->wrapper()->url_stat('public://report.txt', STREAM_URL_STAT_QUIET);

    self::assertIsArray($stat);
    self::assertSame(42, $stat[7], 'size from the fileSize getter');
    self::assertSame(1700000000, $stat[9], 'mtime from lastModified');
    self::assertSame(1700000000, $stat[8], 'atime synthesized as mtime');
    self::assertSame(1700000000, $stat[10], 'ctime likewise');
    self::assertSame(1, $stat[3], 'link count concrete');
    self::assertGreaterThan(0, $stat[2] & 0100000, 'regular-file type bit');
    self::assertSame(0, $stat[2] & 0040000);
    self::assertSame(getmyuid(), $stat[4]);
    self::assertSame(getmygid(), $stat[5]);
    self::assertSame(42, $stat['size']);
    self::assertSame(1700000000, $stat['mtime']);
    foreach (range(0, 12) as $index) {
      self::assertIsInt($stat[$index], "numeric field $index must be an integer");
    }
  }

  /**
   * Only the mapped metadata questions are asked — the routing proof.
   */
  public function testStatAsksOnlyMappedQuestions(): void {
    $this->files['r.txt'] = ['size' => 1, 'mtime' => 2, 'visibility' => 'public', 'contents' => 'x'];

    $this->wrapper()->url_stat('public://r.txt', STREAM_URL_STAT_QUIET);

    $ignore = ['definitionFor', 'fileExists', 'directoryExists'];
    $metadata = array_values(array_filter($this->calls, static fn(string $c): bool => !in_array($c, $ignore, TRUE)));
    self::assertSame(['fileSize', 'lastModified', 'visibility'], $metadata);
    self::assertNotContains('read', $this->calls, 'metadata never pulls content');
    self::assertNotContains('write', $this->calls);
  }

  /**
   * A missing path answers FALSE, asking existence questions only.
   */
  public function testMissingPathAnswersFalse(): void {
    self::assertFalse($this->wrapper()->url_stat('public://nowhere.txt', STREAM_URL_STAT_QUIET));
    self::assertSame(['fileExists', 'directoryExists'], array_values(array_filter($this->calls, static fn(string $c): bool => $c !== 'definitionFor')));
  }

  /**
   * A directory carries the directory bit and never asks the size getter.
   */
  public function testDirectoryStat(): void {
    $this->dirs[] = 'gallery';

    $stat = $this->wrapper()->url_stat('public://gallery', STREAM_URL_STAT_QUIET);

    self::assertIsArray($stat);
    self::assertGreaterThan(0, $stat[2] & 0040000);
    self::assertSame(0, $stat[2] & 0100000);
    self::assertSame(0, $stat[7], 'directories do not report a file size');
    self::assertNotContains('fileSize', $this->calls);
  }

  /**
   * Mode permission bits come from visibility mapped through the binding.
   *
   * The site's long-standing Drupal modes (public files 0664, public
   * directories 0775, owner-only for private) answer when the binding
   * configures no explicit map; a configured unix_visibility map wins.
   */
  public function testModeBitsFromVisibilityAndBinding(): void {
    $this->files['a.txt'] = ['size' => 1, 'mtime' => 2, 'visibility' => 'public', 'contents' => 'x'];
    $stat = $this->wrapper()->url_stat('public://a.txt', 0);
    self::assertSame(0664, $stat[2] & 0777, 'public file takes the site file mode');

    $this->files['b.txt'] = ['size' => 1, 'mtime' => 2, 'visibility' => 'private', 'contents' => 'x'];
    $stat = $this->wrapper()->url_stat('public://b.txt', 0);
    self::assertSame(0600, $stat[2] & 0777, 'private file is owner-only');

    $this->definition = [
      'plugin' => 'local',
      'config' => ['unix_visibility' => ['file' => ['public' => 0600, 'private' => 0600]]],
      'local' => TRUE,
    ];
    $this->files['c.txt'] = ['size' => 1, 'mtime' => 2, 'visibility' => 'public', 'contents' => 'x'];
    $stat = $this->wrapper()->url_stat('public://c.txt', 0);
    self::assertSame(0600, $stat[2] & 0777, "the binding's configured map is the authority");
  }

  /**
   * A backend that cannot answer metadata still yields a complete row.
   *
   * The library's nullable attributes can legitimately report no size or
   * time; three getters delegate the null through. When the operator
   * cannot answer a field at all, the synthesis falls back to a concrete
   * default rather than poisoning PHP's stat array with a null.
   */
  public function testUnanswerableFieldsFallToConcreteDefaults(): void {
    $operator = $this->createMock(FilesystemOperator::class);
    $operator->method('fileExists')->willReturn(TRUE);
    $operator->method('directoryExists')->willReturn(FALSE);
    $operator->method('fileSize')->willThrowException(UnableToRetrieveMetadata::fileSize('d.bin', 'no size'));
    $operator->method('lastModified')->willThrowException(UnableToRetrieveMetadata::lastModified('d.bin', 'no mtime'));
    $operator->method('visibility')->willReturn('public');

    $stat = $this->wrapperWith($operator)->url_stat('public://d.bin', 0);

    self::assertIsArray($stat);
    self::assertSame(0, $stat[7], 'a size that cannot be answered reports zero, not null');
    self::assertIsInt($stat[9]);
    self::assertGreaterThan(0, $stat[2] & 0100000, 'the type bit still answers from fileExists');
    foreach (range(0, 12) as $index) {
      self::assertIsInt($stat[$index], "no field may be null even when metadata fails: $index");
    }
  }

  /**
   * Non-local bindings synthesize portable public defaults.
   */
  public function testNonLocalBindingAnswersConcreteModes(): void {
    $this->definition = ['plugin' => 'remote', 'config' => [], 'local' => FALSE];
    $this->files['o.txt'] = ['size' => 3, 'mtime' => 4, 'visibility' => 'public', 'contents' => 'x'];

    $stat = $this->wrapper()->url_stat('public://o.txt', 0);

    self::assertSame(0644, $stat[2] & 0777, 'a store with no mode concept reports the portable public file mode');
    self::assertGreaterThan(0, $stat[2] & 0100000);
  }

  /**
   * A scheme that resolves core-native answers FALSE (never half-served).
   */
  public function testUnboundResolutionRefusesAnswers(): void {
    $this->unbound = TRUE;

    self::assertFalse($this->wrapper()->url_stat('public://anything.txt', STREAM_URL_STAT_QUIET));
    self::assertFalse($this->wrapper()->mkdir('public://whatever', 0775, 0));
    self::assertFalse($this->wrapper()->realpath());
  }

  /**
   * The canonical-path question: real for local roots, FALSE elsewhere.
   */
  public function testRealpathFollowsLocality(): void {
    $this->definition = ['plugin' => 'local', 'config' => ['root' => '/srv/files/private'], 'local' => TRUE];
    $wrapper = $this->wrapper();
    $wrapper->setUri('private://img/photo.jpg');
    self::assertSame('/srv/files/private/img/photo.jpg', $wrapper->realpath(), 'local storage answers the root-joined canonical path, lexically anchored, never realpath()-ed');

    $this->definition = ['plugin' => 'remote', 'config' => [], 'local' => FALSE];
    $other = $this->wrapper();
    $other->setUri('private://x');
    self::assertFalse($other->realpath(), 'a store without local paths answers FALSE, as every non-local wrapper already does');
  }

  /**
   * Mkdir creates through the operator; refusal surfaces as FALSE.
   */
  public function testMkdirCreatesAndReportsFailure(): void {
    self::assertTrue($this->wrapper()->mkdir('public://new/dir', 0775, STREAM_MKDIR_RECURSIVE));
    self::assertContains('createDirectory', $this->calls, 'recursive creation is the library behavior — parents built in one call');

    $this->calls = [];
    self::assertFalse($this->wrapper()->mkdir('public://denied', 0775, 0));
  }

  /**
   * Rmdir refuses a non-empty directory; only empties are removed.
   */
  public function testRmdirRefusesNonEmpty(): void {
    $this->dirs[] = 'stuff';
    $this->children['stuff'] = ['keep.txt' => FALSE];

    self::assertFalse($this->wrapper()->rmdir('public://stuff', 0));
    self::assertNotContains('deleteDirectory', $this->calls, 'a refused removal never asks the operator to wipe the prefix');

    $this->children['emptydir'] = [];
    $this->dirs[] = 'emptydir';
    $this->calls = [];
    self::assertTrue($this->wrapper()->rmdir('public://emptydir', 0));
    self::assertContains('deleteDirectory', $this->calls);
  }

  /**
   * Unlink removes existing files; missing paths fail, never no-op true.
   */
  public function testUnlinkSemantics(): void {
    $this->files['gone.txt'] = ['size' => 1, 'mtime' => 1, 'visibility' => 'public', 'contents' => 'x'];
    self::assertTrue($this->wrapper()->unlink('public://gone.txt'));

    $this->calls = [];
    self::assertFalse($this->wrapper()->unlink('public://never.txt'));
  }

  /**
   * Rename routes to the operator's native move — server-side, not bytes.
   */
  public function testRenameUsesNativeMove(): void {
    $this->files['a.txt'] = ['size' => 1, 'mtime' => 1, 'visibility' => 'public', 'contents' => 'payload'];

    self::assertTrue($this->wrapper()->rename('public://a.txt', 'public://sub/b.txt'));
    self::assertContains('move', $this->calls);
    self::assertNotContains('read', $this->calls, 'moving never pulls content through userland');
    self::assertNotContains('write', $this->calls);

    $this->calls = [];
    self::assertFalse($this->wrapper()->rename('public://missing.txt', 'public://other.txt'));
    self::assertNotContains('move', $this->calls, 'a missing source fails before any move is attempted');
  }

  /**
   * Directory iteration answers dot entries then the operator's children.
   *
   * PHP injects nothing for user wrappers (verified): a real folder's
   * scandir carries '.' and '..' because readdir yields them from the OS
   * handle, so parity means the wrapper yields them itself, then the
   * prefix-stripped child names the operator lists, one at a time.
   */
  public function testDirIterationWithDotsThenChildren(): void {
    $this->dirs[] = 'shelf';
    $this->children['shelf'] = ['a.txt' => FALSE, 'nested' => TRUE];
    $wrapper = $this->wrapper();

    self::assertTrue($wrapper->dir_opendir('public://shelf', 0));
    self::assertSame('.', $wrapper->dir_readdir());
    self::assertSame('..', $wrapper->dir_readdir());
    self::assertSame('a.txt', $wrapper->dir_readdir());
    self::assertSame('nested', $wrapper->dir_readdir());
    self::assertFalse($wrapper->dir_readdir());
    self::assertTrue($wrapper->dir_rewinddir());
    self::assertSame('.', $wrapper->dir_readdir());
    self::assertTrue($wrapper->dir_closedir());

    $missing = $this->wrapper();
    self::assertFalse($missing->dir_opendir('public://nowhere', 0));
  }

  /**
   * Touch preserves content and re-asks existence — no raw path work.
   */
  public function testTouchPreservesAndRoutesThroughOperator(): void {
    $this->files['t.txt'] = ['size' => 4, 'mtime' => 1700000000, 'visibility' => 'public', 'contents' => 'same'];

    self::assertTrue($this->wrapper()->stream_metadata('public://t.txt', STREAM_META_TOUCH, [1700000001, 1700000001]));
    self::assertContains('read', $this->calls, 'touch on an existing file re-writes it through the operator to move times');
    self::assertContains('write', $this->calls);

    $this->calls = [];
    self::assertTrue($this->wrapper()->stream_metadata('public://fresh.txt', STREAM_META_TOUCH, []));
    self::assertContains('write', $this->calls, 'touch on a missing file creates it empty');
  }

  /**
   * Every metadata option except touch has no operator answer: refused,
   * silently, and never reaching storage. The storage abstraction expresses
   * permissions as public/private visibility — never as raw mode bits —
   * and ownership has no representation at all. Half-mapping some chmod
   * values while refusing others would make a caller's success meaningless,
   * so the complete non-touch family answers FALSE before any question is
   * asked. The touch case is the only option PHP's stream layer passes for
   * a supported operation (touch()); this matrix pins the other five
   * constants the protocol defines.
   */
  #[DataProvider('unsupportedMetadataOptions')]
  public function testUnsupportedStreamMetadataOptionsRefuseWithoutTouchingStorage(int $option): void {
    $wrapper = $this->wrapper();
    $asked_before = $this->calls;
    self::assertFalse($wrapper->stream_metadata('public://t.txt', $option, 0700));
    self::assertSame($asked_before, $this->calls, 'A refused metadata option never reaches the operator.');
  }

  /**
   * The metadata options PHP's stream protocol defines beyond touch.
   *
   * @return array<string, array{0: int}>
   *   One data set per option constant.
   */
  public static function unsupportedMetadataOptions(): array {
    return [
      'owner name' => [STREAM_META_OWNER_NAME],
      'owner' => [STREAM_META_OWNER],
      'group name' => [STREAM_META_GROUP_NAME],
      'group' => [STREAM_META_GROUP],
      'access (chmod)' => [STREAM_META_ACCESS],
    ];
  }

  /**
   * Wires a factory whose resolution always raises the given error.
   *
   * @param \Throwable $error
   *   The typed binding error the storage seam raises on every question.
   * @param \Psr\Log\LoggerInterface&\PHPUnit\Framework\MockObject\MockObject $logger
   *   Receives the channel double so tests can pin records.
   * @param int $resolutions
   *   How many times the wrapper may consult the factory.
   *
   * @return \Drupal\flysystem\StreamWrapper\FlysystemPublicStream
   *   A fresh wrapper bound to the broken scheme.
   */
  private function brokenBindingWrapper(\Throwable $error, &$logger, int $resolutions = 1): FlysystemPublicStream {
    $factory = $this->createMock(OperatorFactory::class);
    $factory->expects(self::exactly($resolutions))->method('definitionFor')
      ->with('public')
      ->willThrowException($error);
    $logger = $this->createMock(LoggerInterface::class);
    $container = $this->createMock(ContainerInterface::class);
    $container->method('get')
      ->willReturnCallback(fn(string $id) => match ($id) {
        'flysystem.operator_factory' => $factory,
        'logger.channel.flysystem' => $logger,
        default => NULL,
      });
    $container->method('getParameter')->with('app.root')->willReturn('/srv/app');
    \Drupal::setContainer($container);
    return new FlysystemPublicStream();
  }

  /**
   * A broken binding refuses every metadata/directory operation.
   *
   * The graceful posture for binding errors is one answer everywhere:
   * a configuration defect reached through any wrapper method fails that
   * operation and records one actionable error per instance, never an
   * exception escaping into PHP's stream layer.
   */
  #[DataProvider('metadataAndDirectoryOperations')]
  public function testBrokenBindingRefusesEveryMetadataAndDirectoryOperation(callable $operation): void {
    $error = new UnknownAdapterPluginException('nosuch', 'public');
    $wrapper = $this->brokenBindingWrapper($error, $logger);
    $logger->expects(self::once())->method('error')->with(
      self::identicalTo($error->getMessage()),
      self::callback(static function ($context) use ($error): bool {
        return ($context['scheme'] ?? NULL) === 'public' && ($context['exception'] ?? NULL) === $error;
      }),
    );
    self::assertFalse($operation($wrapper), 'A broken binding must answer this operation as a refusal.');
  }

  /**
   * The unavailable-backend error is not recorded twice on this path.
   *
   * The storage seam records that failure itself before throwing; the
   * wrapper swallows it without a second record for the one event.
   */
  public function testUnavailableBackendNotRecordedTwiceOnMetadataPath(): void {
    $wrapper = $this->brokenBindingWrapper(new FlysystemPackageMissingException('sftp'), $logger);
    $logger->expects(self::never())->method('error');
    self::assertFalse($wrapper->url_stat('public://x', 0));
  }

  /**
   * One broken instance asks the factory once, however many operations.
   *
   * The refusal memo is shared: after the first typed error the instance
   * answers every later operation from the memo without re-entering the
   * storage seam or repeating the record.
   */
  public function testBrokenBindingResolvesOnceAcrossOperations(): void {
    $wrapper = $this->brokenBindingWrapper(new UnknownAdapterPluginException('nosuch', 'public'), $logger, 1);
    $logger->expects(self::once())->method('error');
    self::assertFalse($wrapper->url_stat('public://x', 0));
    self::assertFalse($wrapper->mkdir('public://d', 0775, 0));
    self::assertFalse($wrapper->dir_opendir('public://d', 0));
  }

  /**
   * Every metadata/directory entry point on a fresh broken instance.
   *
   * @return array<string, array{0: callable}>
   *   Named closures applying one wrapper method and returning its
   *   PHP-visible answer.
   */
  public static function metadataAndDirectoryOperations(): array {
    return [
      'stat a file' => [static fn(FlysystemPublicStream $w) => $w->url_stat('public://a.txt', 0)],
      'canonical path' => [static fn(FlysystemPublicStream $w) => $w->realpath()],
      'create directory' => [static fn(FlysystemPublicStream $w) => $w->mkdir('public://d', 0775, 0)],
      'remove directory' => [static fn(FlysystemPublicStream $w) => $w->rmdir('public://d', 0)],
      'delete file' => [static fn(FlysystemPublicStream $w) => $w->unlink('public://a.txt')],
      'move file' => [static fn(FlysystemPublicStream $w) => $w->rename('public://a.txt', 'public://b.txt')],
      'open directory' => [static fn(FlysystemPublicStream $w) => $w->dir_opendir('public://d', 0)],
      'touch file' => [static fn(FlysystemPublicStream $w) => $w->stream_metadata('public://a.txt', STREAM_META_TOUCH, [])],
    ];
  }

}
