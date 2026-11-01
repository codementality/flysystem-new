<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Unit\Plugin;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\flysystem\Adapter\FlysystemAdapterInterface;
use Drupal\flysystem\Adapter\LocalStorageInterface;
use Drupal\flysystem\Plugin\FlysystemAdapter\Local;
use League\Flysystem\Filesystem;
use League\Flysystem\PathTraversalDetected;
use League\Flysystem\Visibility;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests the local adapter plugin: root handling and mode posture.
 *
 * The adapter plugin is created through the plugin system, so the
 * binding's merged configuration arrives in its constructor and the
 * league adapter object is produced once by adapter(). The operator
 * wrapping (visibility defaults carrying the site's chmod settings, the
 * traversal posture the factory decides per adapter kind) is assembled
 * the same way the operator factory assembles it, so these are the same
 * end-to-end guarantees the shipped plugin contract carries: relative
 * roots anchor to the app root (never the process CWD), '..' segments
 * collapse lexically (never realpath(), which would break
 * not-yet-created roots and chase symlinks the OS owns), nothing is
 * created at construction, and the Drupal mode defaults apply to
 * writes. A leading '../' in a target throws through the operator —
 * root escape is refused, never emulated.
 */
#[Group('flysystem')]
#[CoversNothing]
final class LocalAdapterPluginTest extends TestCase {

  /**
   * Synthetic app root (webroot) all fixtures anchor against.
   */
  private string $appRoot = '';

  /**
   * A second directory standing in for an unrelated process CWD.
   */
  private string $otherCwd = '';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $base = sys_get_temp_dir() . '/flysystem-local-plugin-' . getmypid();
    $this->appRoot = $base . '/web';
    $this->otherCwd = $base . '/elsewhere';
    mkdir($this->appRoot, 0777, TRUE);
    mkdir($this->otherCwd, 0777, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    new \Drupal\Core\Site\Settings([]);
    chdir(sys_get_temp_dir());
    exec('rm -rf ' . escapeshellarg(dirname($this->appRoot)));
    parent::tearDown();
  }

  /**
   * Builds the plugin the way the plugin factory would: config in.
   */
  private function plugin(array $config): Local {
    $definition = [
      'id' => 'local',
      'class' => Local::class,
      'label' => new TranslatableMarkup('Local files'),
      'provider' => 'flysystem',
    ];
    return new Local($this->appRoot, $config, 'local', $definition);
  }

  /**
   * Assembles an operator the way the factory does: adapter + defaults.
   */
  private function operator(array $config): Filesystem {
    $options = [
      'visibility' => $config['visibility'] ?? Visibility::PUBLIC,
    ];
    if (isset($config['directory_visibility'])) {
      $options['directory_visibility'] = (string) $config['directory_visibility'];
    }
    if (array_key_exists('allow_relative_path_traversal', $config)) {
      $options['allow_relative_path_traversal'] =
        (bool) $config['allow_relative_path_traversal'];
    }
    return new Filesystem($this->plugin($config)->adapter(), $options);
  }

  /**
   * The plugin implements the adapter contract and declares locality.
   */
  public function testImplementsContractAndLocalStorageFeature(): void {
    $plugin = $this->plugin(['root' => 'files']);
    self::assertInstanceOf(FlysystemAdapterInterface::class, $plugin);
    self::assertInstanceOf(
      LocalStorageInterface::class,
      $plugin,
      'The local backend declares the local-storage feature so the wiring can answer the webroot-containment question from data.'
    );
  }

  /**
   * The availability answer reflects the vendored package, honestly.
   */
  public function testIsAvailableReflectsVendoredPackage(): void {
    self::assertTrue(
      $this->plugin(['root' => 'files'])->isAvailable(),
      'league/flysystem-local is a runtime require — availability is TRUE and the plugin must say so.'
    );
  }

  /**
   * A relative root anchors to the app root, never the process CWD.
   */
  public function testRelativeRootAnchorsToAppRootRegardlessOfCwd(): void {
    $operator = $this->operator(['root' => 'files']);

    foreach ([$this->appRoot, $this->otherCwd, dirname($this->appRoot)] as $cwd) {
      chdir($cwd);
      $operator->write("probe-$cwd.txt", 'anchored');
      self::assertFileExists($this->appRoot . '/files/probe-' . $cwd . '.txt', "From CWD $cwd the relative root must still land inside the app root.");
      if ($cwd !== $this->appRoot) {
        self::assertFileDoesNotExist($cwd . '/files/probe-' . $cwd . '.txt', "From CWD $cwd nothing may be created relative to the process directory.");
      }
    }
  }

  /**
   * Two-dot segments in configured roots collapse lexically, no realpath.
   */
  public function testDotDotSegmentsCollapseLexically(): void {
    $operator = $this->operator(['root' => 'sites/default/../../private']);
    $operator->write('secret.txt', 'x');
    self::assertFileExists($this->appRoot . '/private/secret.txt', 'Lexical collapse must place the root one level up without any intermediate path existing on disk.');
    self::assertDirectoryDoesNotExist($this->appRoot . '/sites', 'Collapse happens before any path component is touched on disk.');
  }

  /**
   * Construction and adapter building against a missing root are inert.
   */
  public function testNonexistentRootWithoutSideEffectsOrErrors(): void {
    $operator = $this->operator(['root' => 'not-created-yet']);
    self::assertDirectoryDoesNotExist($this->appRoot . '/not-created-yet', 'Reading config and building an operator must not create anything — core never does this at config time.');
    $operator->write('late.txt', 'content appears on first write');
    self::assertSame('content appears on first write', $operator->read('late.txt'));
    self::assertDirectoryExists($this->appRoot . '/not-created-yet');
  }

  /**
   * An absolute root passes through verbatim.
   */
  public function testAbsoluteRootPassesThroughVerbatim(): void {
    $absolute = $this->otherCwd . '/abs-root';
    $operator = $this->operator(['root' => $absolute]);
    $operator->write('here.txt', 'yes');
    self::assertFileExists($absolute . '/here.txt');
  }

  /**
   * The private-files convention: '../' roots outside the webroot work.
   */
  public function testPrivateConventionRelativeEscapeRoundTrip(): void {
    $operator = $this->operator(['root' => '../private-outside']);
    $operator->write('pf.txt', 'private lives outside the webroot by convention');
    self::assertSame('private lives outside the webroot by convention', $operator->read('pf.txt'));
    self::assertFileExists(dirname($this->appRoot) . '/private-outside/pf.txt');
  }

  /**
   * A leading '..' in a target throws through the operator.
   */
  public function testLeadingEscapeInTargetThrows(): void {
    $operator = $this->operator([
      'root' => 'files',
      'allow_relative_path_traversal' => TRUE,
    ]);
    $this->expectException(PathTraversalDetected::class);
    $operator->write('../escape.txt', 'nope');
  }

  /**
   * Interior '..' resolves safely inside the bound root.
   */
  public function testInteriorTraversalResolvesWithinRoot(): void {
    $operator = $this->operator([
      'root' => 'files',
      'allow_relative_path_traversal' => TRUE,
    ]);
    $operator->write('deep/nested.txt', 'v');
    $read = $operator->readStream('deep/../deep/nested.txt');
    self::assertSame('v', stream_get_contents($read), 'Local-mode operators keep library-default relative resolution inside the root.');
  }

  /**
   * Unix visibility config passes through to the library.
   */
  public function testUnixVisibilityConverterOptionsPassThrough(): void {
    $operator = $this->operator([
      'root' => 'files',
      'unix_visibility' => [
        'file' => ['public' => 0644, 'private' => 0600],
        'dir' => ['public' => 0755, 'private' => 0700],
      ],
    ]);
    $operator->write('priv.txt', 'x', ['visibility' => 'private']);
    $perms = fileperms($this->appRoot . '/files/priv.txt') & 0777;
    self::assertSame(0600, $perms, 'Configured private file mode must be honored — the converter pass-through is the config-domain proof.');
  }

  /**
   * Reads through the operator dispatch under this plugin root.
   */
  public function testRoundTripThroughOperator(): void {
    $operator = $this->operator(['root' => 'round']);
    $operator->write('a.txt', 'hello');
    self::assertSame('hello', $operator->read('a.txt'));
    $paths = [];
    foreach ($operator->listContents('', TRUE) as $item) {
      $paths[] = $item->path();
    }
    self::assertSame(['a.txt'], $paths);
  }

  /**
   * Default writes land with the Drupal file mode, not league's.
   */
  public function testDefaultFileModeIsDrupalFileChmod(): void {
    new \Drupal\Core\Site\Settings([]);
    $operator = $this->operator(['root' => 'files']);
    $operator->write('mode.txt', 'x');
    $mode = fileperms($this->appRoot . '/files/mode.txt') & 0777;
    self::assertSame(0664, $mode, 'Unconfigured writes carry the Drupal default file mode (0664), not league\'s 0644 — upgraded sites keep their permission expectations.');
  }

  /**
   * The file_chmod_file setting drives the public write mode.
   */
  public function testFileChmodFileSettingDrivesPublicMode(): void {
    new \Drupal\Core\Site\Settings(['file_chmod_file' => 0660]);
    $operator = $this->operator(['root' => 'files']);
    $operator->write('c.txt', 'x');
    $mode = fileperms($this->appRoot . '/files/c.txt') & 0777;
    self::assertSame(0660, $mode, 'The site file_chmod_file setting must drive the public-write mode, exactly as the earlier module generation read it.');
  }

  /**
   * The 'permissions' config shape is accepted as an alias.
   */
  public function testLegacyPermissionsAliasAccepted(): void {
    new \Drupal\Core\Site\Settings([]);
    $operator = $this->operator([
      'root' => 'files',
      'permissions' => [
        'file' => ['public' => 0666, 'private' => 0600],
        'dir' => ['public' => 0777, 'private' => 0700],
      ],
    ]);
    $operator->write('legacy.txt', 'x');
    $mode = fileperms($this->appRoot . '/files/legacy.txt') & 0777;
    self::assertSame(0666, $mode, 'A legacy permissions map must work unchanged — the plugin-scope half of the backward-compatibility promise.');
  }

  /**
   * Link handling: disallowed by default (throws), skippable by config.
   */
  public function testLinkHandlingSkipVersusDisallow(): void {
    new \Drupal\Core\Site\Settings([]);
    $files = $this->appRoot . '/links';
    mkdir($files, 0777, TRUE);
    file_put_contents($files . '/real.txt', 'real');
    symlink($files . '/real.txt', $files . '/link.txt');

    $disallow = $this->operator(['root' => 'links']);
    $threw = FALSE;
    try {
      foreach ($disallow->listContents('', FALSE) as $item) {
      }
    }
    catch (\Throwable $e) {
      $threw = TRUE;
    }
    self::assertTrue($threw, 'The default posture disallows links: listing a directory holding a symlink must throw.');

    $skip = $this->operator(['root' => 'links', 'link_handling' => 'skip']);
    $seen = [];
    foreach ($skip->listContents('', FALSE) as $item) {
      $seen[] = $item->path();
    }
    self::assertNotContains('link.txt', $seen, 'link_handling: skip must omit symlinked entries from listings.');
    self::assertContains('real.txt', $seen, 'Regular entries are still listed when links are skipped.');
  }

  /**
   * Configuration is read once: the same adapter object comes back twice.
   */
  public function testAdapterObjectIsBuiltOnceAndReused(): void {
    $plugin = $this->plugin(['root' => 'files']);
    self::assertSame(
      $plugin->adapter(),
      $plugin->adapter(),
      'A plugin instance holds its configuration and produces its league adapter once — repeated calls must not rebuild it.'
    );
  }

}
