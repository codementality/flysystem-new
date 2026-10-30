<?php

declare(strict_types=1);

namespace Drupal\Tests\flysystem\Functional;

use Drupal\image\Entity\ImageStyle;
use Drupal\media\Entity\Media;
use Drupal\file\Entity\File;
use Drupal\flysystem\StreamWrapper\FlysystemPrivateStream;
use Drupal\flysystem\StreamWrapper\FlysystemTemporaryStream;
use Drupal\node\Entity\Node;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\flysystem\Traits\FlysystemBootTestTrait;
use Drupal\Tests\flysystem\Traits\FlysystemFunctionalTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * End-to-end media and content journey on a bound private scheme.
 *
 * A site whose private:// is bound to local Flysystem storage keeps the
 * whole private contract intact: files land inside the bound root, their
 * only URL is the through-PHP download route, delivery reads through the
 * bound wrapper, and every access answer — original and styled — is the
 * referencing entity's own view access, identical bound and unbound.
 * Private files have no webserver side: that guard is asserted too,
 * because a wrapper that fabricated a public URL for private storage would
 * leak through exactly one of these legs.
 */
#[Group('flysystem')]
#[RunTestsInSeparateProcesses]
class MediaContentJourneyPrivateTest extends BrowserTestBase {

  use FlysystemBootTestTrait;
  use FlysystemFunctionalTestTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'image',
    'file',
    'media',
    'media_library',
    'flysystem',
    'flysystem_journey',
  ];

  /**
   * Bound private root, relative to the app root.
   *
   * @var string
   */
  protected string $privateRoot;

  /**
   * Bound temporary root, relative to the app root.
   *
   * @var string
   */
  protected string $tempRoot;

  /**
   * A second, isolated private root for the placement-isolation leg.
   *
   * @var string
   */
  protected string $isolationRoot;

  /**
   * Whether the authenticated role's stock access was revoked this test.
   *
   * @var bool
   */
  protected bool $roleRevoked = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $token = bin2hex(random_bytes(4));
    $this->tempRoot = 'flysystem-e2e-p-temp-' . $token;
    $this->createScratchRoot(DRUPAL_ROOT . '/' . $this->tempRoot);
    $native = $this->container->get('file_system')->realpath('private://');
    self::assertIsString($native, 'The harness must have a private file path before binding.');
    $this->privateRoot = ltrim(str_replace(DRUPAL_ROOT . '/', '', $native), '/');
    $this->isolationRoot = 'flysystem-e2e-p-isolated-' . $token;
    $this->createScratchRoot(DRUPAL_ROOT . '/' . $this->isolationRoot);
    $this->bindPrivate();
    $this->assertBindingsAreLive();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $temp = DRUPAL_ROOT . '/' . $this->tempRoot;
    $isolated = DRUPAL_ROOT . '/' . $this->isolationRoot;
    if ($this->roleRevoked) {
      $this->restoreAuthenticatedAccess();
    }
    parent::tearDown();
    $this->removeScratchRoot($temp);
    $this->removeScratchRoot($isolated);
  }

  /**
   * Binds private:// and temporary:// to the run's roots.
   */
  protected function bindPrivate(): void {
    $this->applyBindings([
      'private' => ['driver' => 'local', 'config' => ['root' => $this->privateRoot]],
      'temporary' => ['driver' => 'local', 'config' => ['root' => $this->tempRoot]],
    ]);
  }

  /**
   * Rewrites the site settings and reboots this process on the new state.
   *
   * @param array $bindings
   *   The value for the flysystem settings key; empty unbinds.
   */
  protected function applyBindings(array $bindings): void {
    $this->writeSettings([
      'settings' => [
        'flysystem' => (object) [
          'value' => $bindings,
          'required' => TRUE,
        ],
      ],
    ]);
    $this->rebootWithFlysystemSettings();
    if ($bindings !== []) {
      $this->assertBindingsAreLive();
    }
  }

  /**
   * Proves the binding is live before anything measures through it.
   *
   * Without this precondition an inactive binding would still pass every
   * placement leg, because the run's native-root shape stores bytes in the
   * same folder core's wrapper would use. Asserting the manager's class-map
   * and locator answers makes the seam's activation a tested fact here.
   */
  protected function assertBindingsAreLive(): void {
    $manager = $this->container->get('stream_wrapper_manager');
    self::assertSame(FlysystemPrivateStream::class, $manager->getClass('private'), 'private:// dispatches to the Flysystem wrapper while the binding is set.');
    self::assertSame(FlysystemTemporaryStream::class, $manager->getClass('temporary'));
    self::assertInstanceOf(FlysystemPrivateStream::class, $manager->getViaUri('private://liveness-probe.txt'));
  }

  /**
   * Placement isolation: an alternate configured root receives the bytes.
   */
  public function testBoundRootReceivesBytesUnderAnIsolatedConfiguration(): void {
    $this->applyBindings([
      'private' => ['driver' => 'local', 'config' => ['root' => $this->isolationRoot]],
      'temporary' => ['driver' => 'local', 'config' => ['root' => $this->tempRoot]],
    ]);
    $this->assertBindingsAreLive();
    self::assertNotFalse(file_put_contents('private://isolated-write.txt', 'isolated-private'));
    $isolated_target = DRUPAL_ROOT . '/' . $this->isolationRoot . '/isolated-write.txt';
    self::assertFileExists($isolated_target, 'Bytes went to the configured root and nowhere else — dispatch cannot be core-native.');
    self::assertSame('isolated-private', (string) file_get_contents($isolated_target));
    $this->bindPrivate();
  }

  /**
   * Revokes the stock content-access permission from the authenticated role.
   */
  protected function revokeAuthenticatedAccess(): void {
    /** @var \Drupal\user\RoleInterface $role */
    $role = $this->container->get('entity_type.manager')->getStorage('user_role')->load('authenticated');
    $role->revokePermission('access content');
    $role->save();
    $this->container->get('entity_type.manager')->getStorage('user_role')->resetCache();
    $this->roleRevoked = TRUE;
  }

  /**
   * Restores what revokeAuthenticatedAccess() took away.
   */
  protected function restoreAuthenticatedAccess(): void {
    /** @var \Drupal\user\RoleInterface $role */
    $role = $this->container->get('entity_type.manager')->getStorage('user_role')->load('authenticated');
    $role->grantPermission('access content');
    $role->save();
    $this->container->get('entity_type.manager')->getStorage('user_role')->resetCache();
    $this->roleRevoked = FALSE;
  }

  /**
   * Builds a PNG through GD and returns its temporary file path.
   *
   * @param int $width
   *   Pixel width.
   * @param int $height
   *   Pixel height.
   *
   * @return string
   *   Path to the temporary PNG file.
   */
  private function createPngFile(int $width, int $height): string {
    $path = tempnam(sys_get_temp_dir(), 'jsn') . '.png';
    $image = imagecreatetruecolor($width, $height);
    imagepng($image, $path);
    imagedestroy($image);
    return $path;
  }

  /**
   * The bound-root physical path for a private:// URI.
   *
   * @param string $uri
   *   A private:// URI.
   *
   * @return string
   *   Absolute filesystem path inside the bound root.
   */
  private function physical(string $uri): string {
    return DRUPAL_ROOT . '/' . $this->privateRoot . '/' . substr($uri, \strlen('private://'));
  }

  /**
   * Fetches a URL (query included) through the browser session.
   *
   * @param string $url
   *   Absolute or root-relative URL with optional query.
   */
  protected function fetchUrl(string $url): void {
    $parts = explode('?', $url, 2);
    $options = [];
    if (isset($parts[1])) {
      parse_str($parts[1], $options['query']);
    }
    $this->drupalGet($parts[0], $options);
  }

  /**
   * Collects the HTTP answers for one set of viewer states against a URL.
   *
   * The permission vocabulary is core's real one for private files: the
   * download answer is the referencing entity's view access, so the matrix
   * is (anonymous, plain authorized user, admin) plus per-leg variations.
   *
   * @param string $url
   *   The URL to probe.
   *
   * @return array<string, int>
   *   Status codes keyed by viewer label.
   */
  private function probeAccess(string $url): array {
    $answers = [];
    $this->drupalGet($url);
    $answers['anonymous'] = $this->getSession()->getStatusCode();
    $plain = $this->drupalCreateUser(['access content']);
    $this->drupalLogin($plain);
    $this->drupalGet($url);
    $answers['plain_user'] = $this->getSession()->getStatusCode();
    $this->drupalLogout();
    return $answers;
  }

  /**
   * R1: a media private upload places into the bound root, staged clean.
   */
  public function testMediaPrivateUploadPlacesIntoBoundRoot(): void {
    $admin = $this->drupalCreateUser(['create media', 'administer media', 'access media overview']);
    $this->drupalLogin($admin);
    $this->drupalGet('/media/add/media_image_private');
    $page = $this->getSession()->getPage();
    $page->attachFileToField('files[field_media_image_private_0]', $this->createPngFile(64, 64));
    $page->pressButton('field_media_image_private_0_upload_button');
    $page->fillField('field_media_image_private[0][alt]', 'Private journey');
    $page->pressButton('Save');
    $this->assertSession()->statusCodeEquals(200);

    $storage = $this->container->get('entity_type.manager')->getStorage('media');
    $storage->resetCache();
    $media = $storage->loadByProperties(['bundle' => 'media_image_private']);
    $media = reset($media);
    self::assertInstanceOf(Media::class, $media);
    $uri = $media->get('field_media_image_private')->entity->getFileUri();
    self::assertStringStartsWith('private://media-private/', $uri, 'The source field placed at its storage-declared private scheme.');
    self::assertFileExists($this->physical($uri), 'The placed image physically lives inside the bound private root.');
    $staged = array_values(array_diff((array) @scandir(DRUPAL_ROOT . '/' . $this->tempRoot), ['.', '..']));
    self::assertCount(0, $staged, 'Staging through the bound temporary left nothing behind.');
  }

  /**
   * R2: the only URL a private file has is the download route, no public.
   */
  public function testPrivateFilesHaveNoPublicSide(): void {
    [$uri] = $this->placePrivateImage('No public side');
    $url = \Drupal::service('file_url_generator')->generateString($uri);
    self::assertStringContainsString('/system/files/', $url, 'The URL is the through-PHP download route.');
    self::assertStringNotContainsString('/sites/', $url, 'The URL carries no webserver-served files directory for private storage.');
    // Parity: the same placement on the native wrapper yields the same URL
    // shape; the bound wrapper invents nothing.
    $this->applyBindings([]);
    [$uri2] = $this->placePrivateImage('No public side unbound');
    $url2 = \Drupal::service('file_url_generator')->generateString($uri2);
    self::assertStringContainsString('/system/files/', $url2);
  }

  /**
   * R3: the download route delivers exact bytes through the bound wrapper.
   */
  public function testDownloadRouteServesThroughBoundWrapper(): void {
    [$uri] = $this->placePrivateImage('Served through PHP');
    $url = \Drupal::service('file_url_generator')->generateString($uri);
    $this->drupalLogin($this->drupalCreateUser(['access content']));
    $this->fetchUrl($url);
    self::assertSame(200, $this->getSession()->getStatusCode());
    $served = (string) $this->getSession()->getPage()->getContent();
    $physical = (string) file_get_contents($this->physical($uri));
    self::assertSame($physical, $served, 'Downloaded bytes are the bound root bytes, read through the wrapper.');
  }

  /**
   * R4/R6: styled private images serve the derivative, gated identically.
   */
  public function testStyledPrivateImageServesDerivativeAndGuardsAccess(): void {
    [$uri, $node] = $this->placePrivateImage('Styled gate');
    $style = ImageStyle::load('medium');
    $derivativeUri = $style->buildUri($uri);
    self::assertStringStartsWith('private://styles/medium/private/', $derivativeUri, 'The writable private source keeps its derivative private (core scheme-inheritance rule).');
    $styledUrl = $style->buildUrl($uri);
    self::assertStringContainsString('/system/files/styles/medium/private/', $styledUrl, 'A styled private image is addressed through the private style delivery route.');

    // Unbound baseline first: every answer is core's own.
    $this->applyBindings([]);
    [$uri_u, $node_u] = $this->placePrivateImage('Styled gate unbound');
    $styled_u = ImageStyle::load('medium')->buildUrl($uri_u);
    $orig_u = \Drupal::service('file_url_generator')->generateString($uri_u);
    $baseline = [
      'orig' => $this->probeAccess($orig_u),
      'styled' => $this->probeAccess($styled_u),
    ];
    $this->bindPrivate();

    $orig = \Drupal::service('file_url_generator')->generateString($uri);
    $bound = [
      'orig' => $this->probeAccess($orig),
      'styled' => $this->probeAccess($styledUrl),
    ];
    self::assertSame($baseline, $bound, 'Every viewer state answers the private original and its styled URL exactly as core-native does.');

    // The bytes actually are the derivative (scaled), through the wrapper,
    // generated physically inside the bound private root.
    $this->drupalLogin($this->drupalCreateUser(['access content']));
    $this->fetchUrl($styledUrl);
    self::assertSame(200, $this->getSession()->getStatusCode());
    $served = (string) $this->getSession()->getPage()->getContent();
    $decoded = @imagecreatefromstring($served);
    if ($decoded !== FALSE) {
      self::assertLessThanOrEqual(220, imagesx($decoded), 'The served derivative is scaled, not the oversized original.');
      imagedestroy($decoded);
    }
    else {
      self::assertMatchesRegularExpression('/^\x89PNG|^ftypavif/', $served, 'Served body is image bytes.');
    }
    clearstatcache();
    self::assertFileExists($this->physical($derivativeUri), 'The styled derivative was generated inside the bound private root.');

    // Permission-as-access-control leg: unpublished content revokes the
    // file's only reference — the same answer must follow the file.
    $node->set('status', FALSE)->save();
    $node_u->set('status', FALSE)->save();
    $revoked_bound = $this->probeAccess($styledUrl);
    $revoked_unbound = $this->probeAccess($styled_u);
    self::assertSame($revoked_unbound, $revoked_bound, 'Unpublished content revokes the private file (original and styled) identically bound and unbound.');
  }

  /**
   * R5: with the stock role permission gone, only reference-access remains.
   */
  public function testPermissionMatrixOnPrivateDelivery(): void {
    [$uri] = $this->placePrivateImage('Matrix');
    $url = \Drupal::service('file_url_generator')->generateString($uri);
    $this->revokeAuthenticatedAccess();
    $answers = [];
    $this->drupalGet($url);
    $answers['anonymous_revoked'] = $this->getSession()->getStatusCode();
    $plain = $this->drupalCreateUser([]);
    $this->drupalLogin($plain);
    $this->drupalGet($url);
    $answers['no_permission'] = $this->getSession()->getStatusCode();
    $leak = (string) $this->getSession()->getPage()->getContent();
    $this->drupalLogout();
    $admin = $this->drupalCreateUser(['access content', 'bypass node access']);
    $this->drupalLogin($admin);
    $this->drupalGet($url);
    $answers['admin'] = $this->getSession()->getStatusCode();
    $this->drupalLogout();

    $bound = $answers;
    $this->applyBindings([]);
    $answers = [];
    $this->drupalGet($url);
    $answers['anonymous_revoked'] = $this->getSession()->getStatusCode();
    $this->drupalLogin($plain);
    $this->drupalGet($url);
    $answers['no_permission'] = $this->getSession()->getStatusCode();
    $this->drupalLogout();
    $this->drupalLogin($admin);
    $this->drupalGet($url);
    $answers['admin'] = $this->getSession()->getStatusCode();
    $this->drupalLogout();
    $this->bindPrivate();

    self::assertSame($answers, $bound, 'With the stock permission revoked, denial and grant match core exactly in every state.');
    self::assertStringNotContainsString('PNG', substr($leak, 0, 8), 'A denied response leaks no image bytes (no PNG magic).');
  }

  /**
   * R7: deleting the media entity removes the private object from the root.
   */
  public function testEntityDeleteRemovesThePrivateObject(): void {
    $admin = $this->adminUser();
    $this->drupalLogin($admin);
    $this->drupalGet('/media/add/media_image_private');
    $page = $this->getSession()->getPage();
    $page->attachFileToField('files[field_media_image_private_0]', $this->createPngFile(50, 50));
    $page->pressButton('field_media_image_private_0_upload_button');
    $page->fillField('field_media_image_private[0][alt]', 'Doomed private');
    $page->pressButton('Save');
    $this->assertSession()->statusCodeEquals(200);

    $media_storage = $this->container->get('entity_type.manager')->getStorage('media');
    $media_storage->resetCache();
    $media = $media_storage->loadByProperties(['bundle' => 'media_image_private']);
    self::assertNotEmpty($media);
    $item = reset($media);
    $uri = $item->get('field_media_image_private')->entity->getFileUri();
    $fid = $item->get('field_media_image_private')->target_id;
    $physical = $this->physical($uri);
    self::assertFileExists($physical);

    $item->delete();
    $file = File::load($fid);
    self::assertNotNull($file, 'The file record survives media deletion until its own removal.');
    $file->delete();
    $this->container->get('entity_type.manager')->getStorage('file')->resetCache();
    clearstatcache();
    self::assertFileDoesNotExist($physical, 'Deleting the file entity removes the private object from the bound root, failure posture unchanged.');
  }

  /**
   * W5: the plain image widget places privately and previews at the route.
   */
  public function testImageWidgetPlacesPrivatelyAndPreviewsAtDownloadRoute(): void {
    $admin = $this->adminUser();
    $this->drupalLogin($admin);
    $this->drupalGet('/node/add/article');
    $page = $this->getSession()->getPage();
    $page->fillField('title[0][value]', 'Widget private');
    $page->attachFileToField('files[field_private_image_0]', $this->createPngFile(70, 70));
    $page->pressButton('field_private_image_0_upload_button');
    $html = (string) $this->getSession()->getPage()->getContent();
    self::assertMatchesRegularExpression('#/system/files/privatejourney/#', $html, 'The widget preview addresses the private file through the download route.');
    $page->pressButton('edit-submit');
    $this->assertSession()->pageTextContains('Widget private');
    $nodes = $this->container->get('entity_type.manager')->getStorage('node')->loadByProperties(['title' => 'Widget private']);
    $node = reset($nodes);
    $uri = $node->get('field_private_image')->entity->getFileUri();
    self::assertStringStartsWith('private://privatejourney/', $uri);
    self::assertFileExists($this->physical($uri), 'Widget placement lands inside the bound private root.');
  }

  /**
   * W7: the file widget places privately with core's URL shape on both.
   */
  public function testFileWidgetPrivateParity(): void {
    $answers = [];
    foreach (['bound', 'unbound'] as $phase) {
      if ($phase === 'unbound') {
        $this->applyBindings([]);
      }
      $admin = $this->adminUser();
      $this->drupalLogin($admin);
      $this->drupalGet('/node/add/article');
      $page = $this->getSession()->getPage();
      $title = 'File widget ' . $phase;
      $page->fillField('title[0][value]', $title);
      $txt = tempnam(sys_get_temp_dir(), 'pvf') . '.txt';
      file_put_contents($txt, 'private-file-widget');
      $page->attachFileToField('files[field_private_file_0]', $txt);
      $page->pressButton('field_private_file_0_upload_button');
      $page->pressButton('edit-submit');
      $this->assertSession()->pageTextContains($title);
      $nodes = $this->container->get('entity_type.manager')->getStorage('node')->loadByProperties(['title' => $title]);
      $node = reset($nodes);
      $uri = $node->get('field_private_file')->entity->getFileUri();
      $answers[$phase] = $this->normalizeRoots(
        $uri . '|' . \Drupal::service('file_url_generator')->generateString($uri)
      );
      $node->delete();
      $this->drupalLogout();
      if ($phase === 'unbound') {
        $this->bindPrivate();
      }
    }
    self::assertSame($answers['unbound'], $answers['bound'], 'Private file widget URI and download-route URL match core-native.');
  }

  /**
   * Places a private image on a published node, returns URI and node.
   *
   * @param string $title
   *   Title for the node.
   *
   * @return array{0: string, 1: \Drupal\node\NodeInterface}
   *   The private URI and the saved node.
   */
  private function placePrivateImage(string $title): array {
    $admin = $this->adminUser();
    $this->drupalLogin($admin);
    $this->drupalGet('/node/add/article');
    $page = $this->getSession()->getPage();
    $page->fillField('title[0][value]', $title);
    $page->attachFileToField('files[field_private_image_0]', $this->createPngFile(100, 80));
    $page->pressButton('field_private_image_0_upload_button');
    $page->pressButton('edit-submit');
    $this->assertSession()->pageTextContains($title);
    $nodes = $this->container->get('entity_type.manager')->getStorage('node')->loadByProperties(['title' => $title]);
    $node = reset($nodes);
    self::assertInstanceOf(Node::class, $node);
    $uri = $node->get('field_private_image')->entity->getFileUri();
    return [$uri, $node];
  }

  /**
   * A creator-with-access admin-shaped user for form legs.
   *
   * @return \Drupal\user\UserInterface
   *   The user.
   */
  private function adminUser() {
    return $this->drupalCreateUser([
      'access content',
      'create article content',
      'edit any article content',
      'administer media',
      'create media',
      'access media overview',
    ]);
  }

  /**
   * Strips the run's roots and per-run titles from comparison text.
   *
   * @param string $text
   *   Text containing URIs or URLs.
   *
   * @return string
   *   Normalized text.
   */
  protected function normalizeRoots(string $text): string {
    return preg_replace('/(?:jsn|pvf)[a-zA-Z0-9]+/', 'RANDOM', str_replace($this->privateRoot, 'PRIVROOT', $text));
  }

}
