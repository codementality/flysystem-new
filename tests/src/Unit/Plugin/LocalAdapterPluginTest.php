<?php

declare(strict_types=1);

namespace Drupal\flysystem\Tests\Unit\Plugin;

use Drupal\Core\Site\Settings;
use Drupal\flysystem\Plugin\FlysystemAdapterInterface;
use Drupal\flysystem\Plugin\FlysystemAdapter\Local;
use Drupal\flysystem\Plugin\LocalStorageInterface;
use League\Flysystem\PathTraversalDetected;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests the local adapter plugin: root handling and permission posture.
 *
 * A backend answers for itself through the plugin contract:
 * Everything here is about the root-handling decisions the local backend
 * owns — anchoring relative roots to the app root (never the process
 * CWD), collapsing '..' lexically (never realpath(), which would break
 * not-yet-created roots and resolve symlinks the OS is supposed to see),
 * and creating nothing at construction time.
 *
 * Posture: a leading '../' in a target throws through the operator (a
 * documented deviation from raw fopen behavior, never an emulated
 * escape); a publicly accessible local root outside the webroot is
 * refused by the settings validator, not by this plugin — the plugin
 * serves any root the resolver admits, including the
 * '/../private-files' convention used for private storage.
 */
#[Group('flysystem')]
#[CoversNothing]
final class LocalAdapterPluginTest extends TestCase {

  /**
   * Synthetic app root (webroot) all fixtures anchor against.
   *
   * @var string
   */
  private string $appRoot = '';

  /**
   * A second directory standing in for an unrelated process CWD.
   *
   * @var string
   */
  private string $otherCwd = '';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $base = sys_get_temp_dir() . '/flysystem-m109-' . getmypid();
    $this->appRoot = $base . '/web';
    $this->otherCwd = $base . '/elsewhere';
    mkdir($this->appRoot, 0777, TRUE);
    mkdir($this->otherCwd, 0777, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    new Settings([]);
    chdir(sys_get_temp_dir());
    exec('rm -rf ' . escapeshellarg(dirname($this->appRoot)));
    parent::tearDown();
  }

  /**
   * Builds a local plugin instance directly (no container in unit tests).
   *
   * @param array<string, mixed> $configuration
   *   Plugin configuration (root and options).
   */
  private function plugin(array $configuration): Local {
    $definition = ['id' => 'local', 'class' => Local::class];
    return new Local($configuration, 'local', $definition, $this->appRoot);
  }

  /**
   * Verifies the plugin is a Flysystem adapter and declares local storage.
   */
  public function testPluginImplementsAdapterContractAndLocalStorageFeature(): void {
    $plugin = $this->plugin(['root' => 'sites/default/files']);
    self::assertInstanceOf(FlysystemAdapterInterface::class, $plugin);
    self::assertTrue($plugin instanceof LocalStorageInterface, "The local backend declares the local-storage feature so the resolver wiring can answer the webroot-containment question truthfully.");
  }

  /**
   * Verifies isAvailable() reflects the vendored package, honestly.
   */
  public function testIsAvailableReflectsVendoredPackage(): void {
    $plugin = $this->plugin(['root' => 'files']);
    self::assertTrue($plugin->isAvailable(), 'league/flysystem-local is a runtime require — availability is TRUE here and the plugin must say so.');
  }

  /**
   * Verifies a relative root anchors to the app root, never process CWD.
   */
  public function testRelativeRootAnchorsToAppRootRegardlessOfCwd(): void {
    $plugin = $this->plugin(['root' => 'files']);
    $operator = $plugin->buildFilesystem();

    $observed = [];
    foreach ([$this->appRoot, $this->otherCwd, dirname($this->appRoot)] as $cwd) {
      chdir($cwd);
      $operator->write("probe-$cwd.txt", 'anchored');
      $observed[$cwd] = [
        'in_app_root' => is_file($this->appRoot . '/files/' . "probe-$cwd.txt"),
        'in_cwd' => is_file($cwd . '/files/' . "probe-$cwd.txt"),
      ];
    }
    foreach ($observed as $cwd => $facts) {
      self::assertTrue($facts['in_app_root'], "From CWD $cwd, the relative root must still land inside the app root.");
      if ($cwd !== $this->appRoot) {
        self::assertFalse($facts['in_cwd'], "From CWD $cwd, nothing may be created relative to the process directory.");
      }
    }
  }

  /**
   * Verifies '..' in configured roots collapses lexically, no realpath.
   */
  public function testDotDotSegmentsCollapseLexically(): void {
    // None of sites/default exist on disk — a realpath() here would fail
    // or move the root; lexical collapse must land exactly one level up.
    $plugin = $this->plugin(['root' => 'sites/default/../../private']);
    $operator = $plugin->buildFilesystem();
    $operator->write('secret.txt', 'x');
    self::assertFileExists($this->appRoot . '/private/secret.txt', 'Lexical collapse of the configured root must place the root one level up, with the intermediate path never needing to exist.');
    self::assertDirectoryDoesNotExist($this->appRoot . '/sites', 'Collapse must happen before any path component is touched on disk.');
  }

  /**
   * Verifies construction against a nonexistent root has no side effects.
   */
  public function testNonexistentRootConstructsWithoutSideEffectsOrErrors(): void {
    $missing = 'not-created-yet';
    $plugin = $this->plugin(['root' => $missing]);
    $operator = $plugin->buildFilesystem();
    self::assertDirectoryDoesNotExist($this->appRoot . '/' . $missing, 'Reading config and building an operator must not create anything — core never does this at config time.');
    $operator->write('late.txt', 'content appears on first write');
    self::assertSame('content appears on first write', $operator->read('late.txt'));
    self::assertDirectoryExists($this->appRoot . '/' . $missing);
  }

  /**
   * Verifies an absolute root passes through verbatim.
   */
  public function testAbsoluteRootPassesThroughVerbatim(): void {
    $absolute = $this->otherCwd . '/abs-root';
    $plugin = $this->plugin(['root' => $absolute]);
    $operator = $plugin->buildFilesystem();
    $operator->write('here.txt', 'yes');
    self::assertFileExists($absolute . '/here.txt');
  }

  /**
   * Verifies the private-files convention: '../' roots outside webroot work.
   */
  public function testPrivateConventionRelativeEscapeRoundTrip(): void {
    $plugin = $this->plugin(['root' => '../private-outside']);
    $operator = $plugin->buildFilesystem();
    $operator->write('pf.txt', 'private lives outside the webroot by convention');
    self::assertSame('private lives outside the webroot by convention', $operator->read('pf.txt'));
    self::assertFileExists(dirname($this->appRoot) . '/private-outside/pf.txt');
  }

  /**
   * Verifies a leading '..' in a target throws through the operator.
   */
  public function testLeadingEscapeInTargetThrows(): void {
    $plugin = $this->plugin(['root' => 'files']);
    $operator = $plugin->buildFilesystem();
    $this->expectException(PathTraversalDetected::class);
    $operator->write('../escape.txt', 'nope');
  }

  /**
   * Verifies interior '..' resolves safely inside the bound root.
   */
  public function testInteriorTraversalResolvesWithinRoot(): void {
    $plugin = $this->plugin(['root' => 'files']);
    $operator = $plugin->buildFilesystem();
    $operator->write('deep/nested.txt', 'v');
    $read = $operator->readStream('deep/../deep/nested.txt');
    self::assertSame('v', stream_get_contents($read), 'Local-mode operators keep library-default relative resolution inside the root.');
  }

  /**
   * Verifies unix visibility config is passed through to the library.
   */
  public function testUnixVisibilityConverterOptionsPassThrough(): void {
    $plugin = $this->plugin([
      'root' => 'files',
      'unix_visibility' => [
        'file' => ['public' => 0644, 'private' => 0600],
        'dir' => ['public' => 0755, 'private' => 0700],
      ],
    ]);
    $operator = $plugin->buildFilesystem();
    $operator->write('priv.txt', 'x', ['visibility' => 'private']);
    $perms = fileperms($this->appRoot . '/files/priv.txt') & 0777;
    self::assertSame(0600, $perms, "Configured private file mode must be honored: the converter pass-through is the config-domain proof.");
  }

  /**
   * Verifies reads through the operator dispatch under this plugin root.
   */
  public function testRoundTripThroughOperator(): void {
    $plugin = $this->plugin(['root' => 'round']);
    $operator = $plugin->buildFilesystem();
    $operator->write('a.txt', 'hello');
    self::assertSame('hello', $operator->read('a.txt'));
    $paths = [];
    foreach ($operator->listContents('', TRUE) as $item) {
      $paths[] = $item->path();
    }
    self::assertSame(['a.txt'], $paths);
  }

  /**
   * Verifies default writes land with the Drupal file mode, not league's.
   */
  public function testDefaultFileModeIsDrupalFileChmod(): void {
    new Settings([]);
    $plugin = $this->plugin(['root' => 'files']);
    $operator = $plugin->buildFilesystem();
    $operator->write('mode.txt', 'x');
    $mode = fileperms($this->appRoot . '/files/mode.txt') & 0777;
    self::assertSame(0664, $mode, 'Unconfigured writes must carry the Drupal default file mode (0664), not league\'s 0644 — 2.x sites keep their permission expectations.');
  }

  /**
   * Verifies the file_chmod_file setting drives the public write mode.
   */
  public function testDrupalChmodSettingsAreRespected(): void {
    new Settings(['file_chmod_file' => 0660]);
    $plugin = $this->plugin(['root' => 'files']);
    $operator = $plugin->buildFilesystem();
    $operator->write('custom.txt', 'x');
    $mode = fileperms($this->appRoot . '/files/custom.txt') & 0777;
    self::assertSame(0660, $mode, 'The site file_chmod_file setting must drive the public-write mode, exactly as 2.x read it.');
  }

  /**
   * Verifies explicit per-binding visibility config overrides the defaults.
   */
  public function testUnixVisibilityConfigOverridesDrupalDefaults(): void {
    new Settings([]);
    $plugin = $this->plugin([
      'root' => 'files',
      'unix_visibility' => [
        'file' => ['public' => 0640, 'private' => 0400],
        'dir' => ['public' => 0750, 'private' => 0700],
      ],
    ]);
    $operator = $plugin->buildFilesystem();
    $operator->write('override.txt', 'x');
    $mode = fileperms($this->appRoot . '/files/override.txt') & 0777;
    self::assertSame(0640, $mode);
  }

  /**
   * Verifies the 2.x 'permissions' config shape is accepted as an alias.
   */
  public function testLegacyPermissionsMapIsAccepted(): void {
    new Settings([]);
    $plugin = $this->plugin([
      'root' => 'files',
      'permissions' => [
        'file' => ['public' => 0666, 'private' => 0600],
        'dir' => ['public' => 0777, 'private' => 0700],
      ],
    ]);
    $operator = $plugin->buildFilesystem();
    $operator->write('legacy.txt', 'x');
    $mode = fileperms($this->appRoot . '/files/legacy.txt') & 0777;
    self::assertSame(0666, $mode, 'A 2.x-style permissions map must work unchanged — the plugin-scope half of the backward-compatibility request on this PR.');
  }

  /**
   * Verifies link handling: disallow (default) versus skip configuration.
   */
  public function testLinkHandlingSkipVersusDisallow(): void {
    new Settings([]);
    $files = $this->appRoot . '/files';
    mkdir($files, 0777, TRUE);
    file_put_contents($this->appRoot . '/target.txt', 'real');
    symlink($this->appRoot . '/target.txt', $files . '/link.txt');

    $disallow = $this->plugin(['root' => 'files'])->buildFilesystem();
    $threw = FALSE;
    try {
      foreach ($disallow->listContents('', FALSE) as $item) {
        // Iteration itself throws when the symlink is yielded under
        // DISALLOW_LINKS.
      }
    }
    catch (\Throwable $e) {
      $threw = TRUE;
    }
    self::assertTrue($threw, 'Default posture disallows links: listing a symlinked entry must throw.');

    $skip = $this->plugin(['root' => 'files', 'link_handling' => 'skip'])->buildFilesystem();
    $seen = [];
    foreach ($skip->listContents('', FALSE) as $item) {
      $seen[] = $item->path();
    }
    self::assertNotContains('link.txt', $seen, 'link_handling: skip must omit symlinked entries from listings.');
  }

}
