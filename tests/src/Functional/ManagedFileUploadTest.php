<?php

declare(strict_types=1);

namespace Drupal\flysystem\Tests\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\image\Entity\ImageStyle;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves the managed upload mechanics work identically bound and native.
 *
 * A real multipart upload through a core form API on a site whose
 * temporary:// and public:// schemes are bound to local storage: the
 * upload stages on the temporary scheme, moves to the public scheme,
 * and saves the managed entity whose size stat runs through the bound
 * wrapper. The placed file answers at a scheme-relative URI, lives
 * physically inside the bound root, and is served by the webserver at
 * the same URL core would generate. Then the whole cycle is replayed
 * with no binding at all: same URIs, same bytes, same page answers —
 * parity is asserted at the product level, not just the stream
 * functions. Finally, an image style generates its derivative into the
 * bound public root, the last write path the managed mechanics cover.
 */
#[Group('flysystem')]
#[RunTestsInSeparateProcesses]
class ManagedFileUploadTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['file', 'image', 'user', 'flysystem', 'flysystem_test_upload'];

  /**
   * The bound public scheme's root folder, relative to the app root.
   *
   * @var string
   */
  protected string $publicRoot;

  /**
   * The bound temporary scheme's root folder, relative to the app root.
   *
   * @var string
   */
  protected string $tempRoot;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $token = bin2hex(random_bytes(4));
    $this->publicRoot = 'flysystem-m2-06-' . $token;
    $this->tempRoot = 'flysystem-m2-06-' . $token . '-temp';
    foreach ([$this->publicRoot, $this->tempRoot] as $dir) {
      if (!is_dir(DRUPAL_ROOT . '/' . $dir)) {
        mkdir(DRUPAL_ROOT . '/' . $dir, 0777, TRUE);
      }
    }
    parent::setUp();
    $this->applyBindings([
      'public' => ['driver' => 'local', 'config' => ['root' => $this->publicRoot]],
      'temporary' => ['driver' => 'local', 'config' => ['root' => $this->tempRoot]],
    ]);
  }

  /**
   * Rewrites the site settings and reboots this process on the new state.
   *
   * Browser requests pick the settings up per-request; the test process
   * needs the same fresh read so its own wrapper writes land in the
   * bound roots. Passing an empty array unbinds and restores core-native.
   *
   * @param array $bindings
   *   The value for the flysystem settings key.
   */
  private function applyBindings(array $bindings): void {
    $this->writeSettings([
      'settings' => [
        'flysystem' => (object) [
          'value' => $bindings,
          'required' => TRUE,
        ],
      ],
    ]);
    $kernel = $this->container->get('kernel');
    $this->container = $kernel->rebuildContainer();
    \Drupal::setContainer($this->container);
    $this->container->get('stream_wrapper_manager')->register();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $root = DRUPAL_ROOT;
    parent::tearDown();
    foreach ([$this->publicRoot, $this->tempRoot] as $dir) {
      $this->rrmdir($root . '/' . $dir);
    }
  }

  /**
   * Best-effort recursive folder removal for the bound roots.
   */
  private function rrmdir(string $dir): void {
    if (!is_dir($dir)) {
      return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
      if ($entry === '.' || $entry === '..') {
        continue;
      }
      $path = $dir . '/' . $entry;
      is_dir($path) ? $this->rrmdir($path) : @unlink($path);
    }
    @rmdir($dir);
  }

  /**
   * Stages, places, serves: the bound cycle answers exactly as promised.
   */
  public function testUploadStagesPlacesAndServesThroughBindings(): void {
    $payload = 'upload-payload';
    $result = $this->performUpload($payload);

    [$uri, $size, $fid] = $result;
    self::assertSame(14, $size, 'The entity save stat must count the placed bytes through the bound wrapper.');

    $real = DRUPAL_ROOT . '/' . $this->publicRoot . '/' . ltrim(substr($uri, strlen('public://')), '/');
    self::assertFileExists($real, 'The placed object physically lives inside the bound root.');
    self::assertSame($payload, file_get_contents($real), 'The bytes are byte-for-byte the upload.');

    $staged_left = @scandir(DRUPAL_ROOT . '/' . $this->tempRoot);
    $staged_left = array_values(array_diff((array) $staged_left, ['.', '..']));
    self::assertCount(0, $staged_left, 'The staging write moved away; nothing was left behind.');

    $file = \Drupal::entityTypeManager()->getStorage('file')->load($fid);
    self::assertNotNull($file);
    self::assertSame($uri, $file->getFileUri(), 'The managed URI stays scheme-relative in the entity.');

  }

  /**
   * A bound public scheme keeps serving files at the URL core used.
   *
   * The production shape of a public binding: the bound root is the very
   * folder the webserver already serves for the public URL prefix, so a
   * file written through the wrapper is reachable at the identical URL
   * — URL-for-URL, byte-for-byte, no rewrite configuration required.
   */
  public function testServingStaysAtTheNativePublicUrl(): void {
    $this->applyBindings([]);
    $native_root = $this->container->get('file_system')->realpath('public://');
    self::assertIsString($native_root);
    $this->applyBindings([
      'public' => ['driver' => 'local', 'config' => ['root' => $native_root]],
      'temporary' => ['driver' => 'local', 'config' => ['root' => $this->tempRoot]],
    ]);

    $payload = 'served-by-webserver';
    [$uri] = $this->performUpload($payload);

    $url = \Drupal::service('file_url_generator')->generateString($uri);
    $this->drupalGet($url);
    self::assertSame(200, $this->getSession()->getStatusCode(), 'The webserver serves the bound public file at the core-shaped URL.');
    self::assertSame($payload, (string) $this->getSession()->getPage()->getContent());
  }

  /**
   * The identical cycle on an unbound site answers the same product.
   *
   * Same scheme-relative URIs, same size stat, same served bytes: the
   * binding changes where files live, never what the mechanics report.
   */
  public function testUploadPlacementsAreCoreNativeParityUnbound(): void {
    $this->applyBindings([]);

    $payload = 'native-parity-payload';
    [$uri, $size] = $this->performUpload($payload);
    self::assertSame(21, $size, 'The native placement size stat matches the payload length.');
    self::assertMatchesRegularExpression('#^public://upload/.+#', $uri);

    $real = \Drupal::service('file_system')->realpath($uri);
    self::assertIsString($real);
    self::assertSame($payload, file_get_contents($real));

    $url = \Drupal::service('file_url_generator')->generateString($uri);
    $this->drupalGet($url);
    self::assertSame(200, $this->getSession()->getStatusCode());
    self::assertSame($payload, (string) $this->getSession()->getPage()->getContent());
  }

  /**
   * An image style generates its derivative into the bound public root.
   *
   * The last managed write path: the derivative lands at the
   * scheme-relative path core computes and physically inside the bound
   * root, with the effect applied.
   */
  public function testDerivativeGeneratesIntoTheBoundRoot(): void {
    self::assertNotFalse(file_put_contents('public://orig.png', $this->makePng(4, 4)));

    $style = ImageStyle::create([
      'name' => 'm2_06_scale',
      'label' => 'M2-06 test scale',
      'effects' => [
        'e1' => ['id' => 'image_scale', 'data' => ['width' => 2, 'height' => NULL, 'upscale' => FALSE], 'weight' => 0],
      ],
    ]);
    $style->save();

    $derivative = $style->buildUri('public://orig.png');
    self::assertTrue($style->createDerivative('public://orig.png', $derivative));

    $real = DRUPAL_ROOT . '/' . $this->publicRoot . '/' . substr($derivative, strlen('public://'));
    self::assertFileExists($real, 'The derivative bytes landed inside the bound root.');
    $size = @getimagesize($derivative);
    self::assertIsArray($size);
    self::assertSame(2, $size[0], 'The scale effect applied through the bound write path.');
  }

  /**
   * Drives one real upload through the test form and parses the answer.
   *
   * @param string $payload
   *   The bytes to upload.
   *
   * @return array{0: string, 1: int, 2: int}
   *   The placed URI, the entity-reported size, and the file id.
   */
  private function performUpload(string $payload): array {
    $source = sys_get_temp_dir() . '/upl' . bin2hex(random_bytes(4)) . '.txt';
    file_put_contents($source, $payload);

    $this->drupalGet('/flysystem-test-upload');
    $field = $this->getSession()->getPage()->findField('files[file]');
    self::assertNotNull($field, 'The test upload form must be reachable.');
    $field->attachFile($source);
    $this->submitForm([], 'Save');

    $text = (string) $this->getSession()->getPage()->getContent();
    self::assertMatchesRegularExpression('/PLACED:public:\/\/upload\/[^:\s]+:\d+:\d+/', $text, 'The form reports staging, placement, size, and the saved entity id in one line.');
    preg_match('/PLACED:(public:\/\/upload\/[^:]+):(\d+):(\d+)/', $text, $m);
    @unlink($source);
    return [$m[1], (int) $m[2], (int) $m[3]];
  }

  /**
   * Builds a tiny PNG through GD.
   *
   * @param int $width
   *   Pixel width.
   * @param int $height
   *   Pixel height.
   *
   * @return string
   *   The encoded PNG bytes.
   */
  private function makePng(int $width, int $height): string {
    $image = imagecreatetruecolor($width, $height);
    ob_start();
    imagepng($image);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);
    return $bytes;
  }

}
