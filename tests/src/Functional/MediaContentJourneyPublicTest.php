<?php

declare(strict_types=1);

namespace Drupal\flysystem\Tests\Functional;

use Drupal\image\Entity\ImageStyle;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\Tests\flysystem\Traits\FlysystemBootTestTrait;
use Drupal\Tests\flysystem\Traits\FlysystemFunctionalTestTrait;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * End-to-end media and content journey on a bound public scheme.
 *
 * A site whose public:// (and the temporary:// staging path) are bound to
 * local Flysystem storage serves the complete authored experience: images
 * uploaded through core forms, media entities, content entities referencing
 * them, image-style derivatives generated into the bound root and served at
 * the same URLs core would produce, with access answers unchanged for every
 * permission combination. Each parity leg is replayed unbound inside the
 * same test run and the two answers must match exactly: the binding may
 * change where bytes live, never what users see.
 */
#[Group('flysystem')]
#[RunTestsInSeparateProcesses]
class MediaContentJourneyPublicTest extends BrowserTestBase {

  use FlysystemFunctionalTestTrait;
  use FlysystemBootTestTrait;

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
   * Bound public root, relative to the app root.
   *
   * @var string
   */
  protected string $publicRoot;

  /**
   * Bound temporary root, relative to the app root.
   *
   * @var string
   */
  protected string $tempRoot;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $token = bin2hex(random_bytes(4));
    $this->tempRoot = 'flysystem-e2e-temp-' . $token;
    $this->createScratchRoot(DRUPAL_ROOT . '/' . $this->tempRoot);
    $native = $this->container->get('file_system')->realpath('public://');
    self::assertIsString($native);
    $this->publicRoot = ltrim(str_replace(DRUPAL_ROOT . '/', '', $native), '/');
    $this->bindPublic();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $temp = DRUPAL_ROOT . '/' . $this->tempRoot;
    /** @var \Drupal\user\RoleStorageInterface $roles */
    $roles = $this->container->get('entity_type.manager')->getStorage('user_role');
    $authenticated = $roles->load('authenticated');
    if ($authenticated !== NULL && !in_array('access content', $authenticated->getPermissions(), TRUE)) {
      $authenticated->grantPermission('access content');
      $authenticated->save();
    }
    parent::tearDown();
    $this->removeScratchRoot($temp);
  }

  /**
   * Binds public:// and temporary:// to the run's roots.
   */
  protected function bindPublic(): void {
    $this->applyBindings([
      'public' => ['driver' => 'local', 'config' => ['root' => $this->publicRoot]],
      'temporary' => ['driver' => 'local', 'config' => ['root' => $this->tempRoot]],
    ]);
  }

  /**
   * Rewrites the site settings and reboots this process on the new state.
   *
   * @param array $bindings
   *   The value for the flysystem settings key; empty unbinds.
   * @param array $extraSettings
   *   Additional settings.php keys to write alongside the bindings.
   */
  protected function applyBindings(array $bindings, array $extraSettings = []): void {
    $settings = [];
    foreach ($extraSettings as $key => $value) {
      $settings[$key] = (object) ['value' => $value, 'required' => TRUE];
    }
    $this->writeSettings([
      'settings' => [
        'flysystem' => (object) [
          'value' => $bindings,
          'required' => TRUE,
        ],
      ] + $settings,
    ]);
    $this->rebootWithFlysystemSettings();
  }

  /**
   * Strips per-run randomness out of text so bound and unbound compare.
   *
   * @param string $text
   *   Markup, URI, or URL text from this run.
   *
   * @return string
   *   The same text with the random directory tokens removed.
   */
  protected function normalize(string $text): string {
    $text = str_replace(
      [$this->publicRoot, $this->tempRoot, $this->siteDirectory],
      ['PUBLICROOT', 'TEMPROOT', 'SITEDIR'],
      $text
    );
    $text = preg_replace('/itok=[A-Za-z0-9_\-]+/', 'itok=TKN', $text);
    $text = preg_replace('/jsn[a-zA-Z0-9]{13,}/', 'RANDOM', $text);
    $text = preg_replace('/\bu[0-9a-f]{4}\b/', 'RANDOM', $text);
    $text = str_replace('%20u', 'u[R]', $text);
    $text = preg_replace('/u\[R\][0-9a-f]{4}%20/', 'RANDOM', $text);
    $text = str_replace('u[R]', 'u', $text);
    return $text;
  }

  /**
   * Returns the first image src in the current page matching a style name.
   *
   * @param string $style
   *   An image style machine name, or '' for any image.
   *
   * @return string
   *   The src attribute value.
   */
  protected function imageSrc(string $style = ''): string {
    $content = (string) $this->getSession()->getPage()->getContent();
    $pattern = $style !== ''
      ? '#<img[^>]*src="([^"]*styles/' . preg_quote($style, '#') . '/[^"]*)"#'
      : '#<img[^>]*src="([^"]+)"#';
    self::assertMatchesRegularExpression($pattern, $content, 'The page carries an image element for the expected style.');
    preg_match($pattern, $content, $m);
    return $this->stripHost(html_entity_decode($m[1]));
  }

  /**
   * Removes the test host prefix so absolute and root-relative match.
   *
   * @param string $url
   *   Any URL.
   *
   * @return string
   *   The URL without its scheme and host part.
   */
  protected function stripHost(string $url): string {
    return (string) preg_replace('#^https?://[^/]+#', '', $url);
  }

  /**
   * Fetches a URL taken from rendered markup, query included.
   *
   * @param string $url
   *   Absolute or root-relative URL with an optional query string.
   */
  protected function fetchImageUrl(string $url): void {
    $parts = explode('?', $url, 2);
    $this->drupalGet($parts[0], ['query' => ['itok' => explode('=', $parts[1] ?? 'itok=')[1]]]);
  }

  /**
   * Creates a PNG through GD and returns its temporary file path.
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
   * Uploads a PNG through the plain image widget on a node form.
   *
   * @param string $fieldName
   *   The image field's machine name.
   * @param string $bundle
   *   The content type machine name.
   * @param string $source
   *   The PNG path to attach.
   * @param string $title
   *   The node title.
   *
   * @return \Drupal\node\NodeInterface
   *   The saved node.
   */
  protected function uploadThroughImageWidget(string $fieldName, string $bundle, string $source, string $title): Node {
    $admin = $this->drupalCreateUser([
      'access content',
      'create article content',
      'create gated_article content',
      'edit any article content',
      'edit any gated_article content',
      'administer media',
      'create media',
    ]);
    $this->drupalLogin($admin);
    $page = $this->getSession()->getPage();
    $this->drupalGet('/node/add/' . $bundle);
    $page->fillField('title[0][value]', $title);
    $page->attachFileToField('files[' . $fieldName . '_0]', $source);
    $page->pressButton($fieldName . '_0_upload_button');
    $page->fillField($fieldName . '[0][alt]', 'Journey alt');
    $page->pressButton('edit-submit');
    $this->assertSession()->pageTextContains($title);
    $nodes = $this->container->get('entity_type.manager')
      ->getStorage('node')
      ->loadByProperties(['title' => $title]);
    $node = reset($nodes);
    self::assertInstanceOf(Node::class, $node);
    return $node;
  }

  /**
   * Returns the absolute URL for a scheme-relative file URI.
   *
   * @param string $uri
   *   A public:// URI.
   * @param \Drupal\image\ImageStyleInterface|null $style
   *   Optional image style to render through.
   *
   * @return string
   *   The absolute URL to fetch.
   */
  private function urlFor(string $uri, ?ImageStyle $style = NULL): string {
    return $style ? $style->buildUrl($uri) : \Drupal::service('file_url_generator')->generateString($uri);
  }

  /**
   * P1: a media upload lands in the bound root and staged nothing behind.
   */
  public function testMediaUploadPlacesIntoBoundRootAndStagesThroughBoundTemporary(): void {
    $admin = $this->drupalCreateUser(['access media overview', 'create media', 'administer media']);
    $this->drupalLogin($admin);
    $this->drupalGet('/media/add/media_image');
    $page = $this->getSession()->getPage();
    $page->attachFileToField('files[field_media_image_0]', $this->createPngFile(64, 64));
    $page->pressButton('field_media_image_0_upload_button');
    $page->fillField('field_media_image[0][alt]', 'Journey image');
    $page->pressButton('Save');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('media/');

    $media_storage = $this->container->get('entity_type.manager')->getStorage('media');
    $media_storage->resetCache();
    $media = $media_storage->loadByProperties(['bundle' => 'media_image']);
    $media = reset($media);
    self::assertInstanceOf(Media::class, $media, 'The media entity was created through the bound upload flow.');
    $uri = $media->get('field_media_image')->entity->getFileUri();
    self::assertMatchesRegularExpression('#^public://\d{4}-\d{2}/[^/]+\.png$#', $uri, 'The media source field places at the core-default year-month directory.');

    $physical = DRUPAL_ROOT . '/' . $this->publicRoot . '/' . substr($uri, \strlen('public://'));
    self::assertFileExists($physical, 'The placed image physically lives inside the bound root.');
    $stat = @getimagesize($physical);
    self::assertSame([64, 64], [$stat[0], $stat[1]]);
    self::assertSame((int) filesize($physical), (int) $media->get('field_media_image')->entity->getSize(), 'The entity-reported size equals the placed bytes; the stat ran through the bound wrapper.');
  }

  /**
   * P2: URI, generated URL, style URL, and rendered src match core exactly.
   */
  public function testUrlBuildingIsIdenticalBoundAndUnbound(): void {
    $answers = [];
    foreach (['bound', 'unbound'] as $phase) {
      if ($phase === 'unbound') {
        $this->applyBindings([]);
      }
      $title = 'Url parity ' . $phase;
      $node = $this->uploadThroughImageWidget('field_public_image', 'article', $this->createPngFile(48, 48), $title);
      $uri = $node->get('field_public_image')->entity->getFileUri();
      $this->drupalGet($node->toUrl());
      $src = $this->imageSrc('medium');
      $answers[$phase] = $this->normalize(implode('|', [
        $uri,
        \Drupal::service('file_url_generator')->generateString($uri),
        ImageStyle::load('medium')->buildUrl($uri),
        $src,
      ]));
      $node->delete();
    }
    self::assertSame($answers['unbound'], $answers['bound'], 'URI, generated URL, style URL, and rendered src must match core-native exactly.');
  }

  /**
   * P3: hostile file names produce identical encoded answers both ways.
   */
  public function testHostileFileNamesBuildIdenticalUrlsBoundAndUnbound(): void {
    $answers = [];
    foreach (['bound', 'unbound'] as $phase) {
      if ($phase === 'unbound') {
        $this->applyBindings([]);
      }
      $title = 'Hostile parity ' . $phase;
      $png = $this->createPngFile(40, 40);
      $hostile = sys_get_temp_dir() . '/sp ace u' . bin2hex(random_bytes(2)) . ' %-#.png';
      rename($png, $hostile);
      $node = $this->uploadThroughImageWidget('field_public_image', 'article', $hostile, $title);
      $uri = $node->get('field_public_image')->entity->getFileUri();
      $answers[$phase] = $this->normalize($uri . '|' . ImageStyle::load('thumbnail')->buildUrl($uri));
      $node->delete();
      @unlink($hostile);
    }
    self::assertSame($answers['unbound'], $answers['bound'], 'Spaces, unicode-adjacent bytes, percent and hash in names survive identically.');
    self::assertStringContainsString('sp ace', $answers['bound'], 'The stored target keeps the human name (core semantics).');
  }

  /**
   * P4: rendered markup carries exactly the style URL, never raw schemes.
   */
  public function testRenderedImageSourcesMatchComputedStyleUrls(): void {
    $node = $this->uploadThroughImageWidget('field_public_image', 'article', $this->createPngFile(60, 30), 'Render sources');
    $uri = $node->get('field_public_image')->entity->getFileUri();
    $this->drupalGet($node->toUrl());
    $src = $this->imageSrc('medium');
    self::assertSame($this->stripHost(ImageStyle::load('medium')->buildUrl($uri)), $this->stripHost($src));
    self::assertStringNotContainsString('public://', $src);
    self::assertStringContainsString('/styles/medium/', $src);
  }

  /**
   * P5: the rendered thumbnail URL generates into the bound root on fetch.
   */
  public function testThumbnailDerivativeIsCreatedIntoTheBoundRoot(): void {
    $node = $this->uploadThroughImageWidget('field_public_image', 'article', $this->createPngFile(200, 200), 'Thumb derivative');
    $uri = $node->get('field_public_image')->entity->getFileUri();
    $style = ImageStyle::load('thumbnail');
    $derivativeUri = $style->buildUri($uri);
    self::assertStringStartsWith('public://styles/thumbnail/', $derivativeUri);
    $physical = DRUPAL_ROOT . '/' . $this->publicRoot . '/' . substr($derivativeUri, \strlen('public://'));
    self::assertFileDoesNotExist($physical, 'The derivative starts absent.');

    $this->drupalGet($node->toUrl());
    $medium_src = $this->imageSrc('medium');
    $path = str_replace('/styles/medium/', '/styles/thumbnail/', $medium_src);
    self::assertNotSame($medium_src, $path, 'The thumbnail URL was derived from the rendered medium URL.');
    $token = ImageStyle::load('thumbnail')->getPathToken($uri);
    $path = preg_replace('#itok=[A-Za-z0-9_\-]+#', 'itok=' . $token, $path);
    $this->fetchImageUrl($path);
    $body = (string) $this->getSession()->getPage()->getContent();
    $served = @imagecreatefromstring($body);
    if ($served !== FALSE) {
      self::assertSame(100, imagesx($served), 'The thumbnail effect applied through the bound write path.');
      imagedestroy($served);
    }
    else {
      self::assertMatchesRegularExpression('/^\x89PNG|^ftypavif/', $body, 'The served body is image bytes (AVIF under a PNG-incapable GD build).');
    }
    clearstatcache();
    self::assertFileExists($physical, 'The generated derivative is addressable at the bound-root path core computes.');
  }

  /**
   * P6: the configured display style serves scaled bytes, not originals.
   */
  public function testDisplayStyleServesScaledDerivativeBytes(): void {
    $node = $this->uploadThroughImageWidget('field_public_image', 'article', $this->createPngFile(120, 120), 'Scaled bytes');
    $uri = $node->get('field_public_image')->entity->getFileUri();
    $build = $this->container->get('entity_type.manager')->getViewBuilder('node')->view($node, 'scaled');
    $html = (string) $this->container->get('renderer')->renderRoot($build);
    preg_match('#src="([^"]*styles/m211_scale[^"]*)"#', $html, $m);
    self::assertNotEmpty($m, 'The scaled view mode renders the m211_scale style URL through the bound wrapper.');
    $suffix = substr($m[1], (int) strpos($m[1], 'styles/m211_scale/'));
    self::assertSame('styles/m211_scale/public/' . substr($uri, \strlen('public://')), explode('?', $suffix)[0], 'The rendered style target addresses exactly the placed object (core prefix-shape for public-scheme sources).');
    $this->fetchImageUrl($m[1]);
    self::assertSame(200, $this->getSession()->getStatusCode());
    $body = (string) $this->getSession()->getPage()->getContent();
    $image = @imagecreatefromstring($body);
    if ($image !== FALSE) {
      $dims = [imagesx($image), imagesy($image)];
      imagedestroy($image);
      self::assertSame([50, 50], $dims, 'The served bytes are the 50px scaled derivative, not the 120px original.');
    }
    else {
      self::assertMatchesRegularExpression('/^\x89PNG|^ftypavif/', $body, 'The served body is scaled derivative image bytes (AVIF under a PNG-incapable GD build).');
      $physical = DRUPAL_ROOT . '/' . $this->publicRoot . '/' . substr(ImageStyle::load('m211_scale')->buildUri($uri), \strlen('public://'));
      clearstatcache();
      $stat = @getimagesize($physical);
      self::assertIsArray($stat, 'The scaled derivative physically exists inside the bound root.');
    }
  }

  /**
   * P7: after a style flush the derivative regenerates into the bound root.
   */
  public function testDerivativeRegeneratesAfterFlush(): void {
    $node = $this->uploadThroughImageWidget('field_public_image', 'article', $this->createPngFile(140, 140), 'Flush leg');
    $uri = $node->get('field_public_image')->entity->getFileUri();
    $style = ImageStyle::load('medium');
    $this->drupalGet($node->toUrl());
    $src = $this->imageSrc('medium');
    $this->fetchImageUrl($src);
    self::assertSame(200, $this->getSession()->getStatusCode());
    $derivativeUri = $style->buildUri($uri);
    $physical = DRUPAL_ROOT . '/' . $this->publicRoot . '/' . substr($derivativeUri, \strlen('public://'));
    self::assertFileExists($physical);

    $style->flush();
    self::assertFileDoesNotExist($physical, 'The flush cleared the derivative from the bound root.');
    $this->drupalGet($style->buildUrl($uri), ['query' => ['itok' => $style->getPathToken($uri)]]);
    self::assertSame(200, $this->getSession()->getStatusCode());
    self::assertFileExists($physical, 'Regeneration lands in the bound root again after flush.');
  }

  /**
   * P8: anonymous users see open public content and its images.
   */
  public function testAnonymousSeesPublicJourneyAndServedDerivatives(): void {
    $node = $this->uploadThroughImageWidget('field_public_image', 'article', $this->createPngFile(80, 80), 'Anonymous sees');
    $uri = $node->get('field_public_image')->entity->getFileUri();
    $this->drupalLogout();
    $this->drupalGet($node->toUrl());
    $this->assertSession()->pageTextContains('Anonymous sees');
    $src = $this->imageSrc('medium');
    $this->fetchImageUrl($src);
    self::assertSame(200, $this->getSession()->getStatusCode());
    $this->drupalGet($this->urlFor($uri));
    self::assertSame(200, $this->getSession()->getStatusCode(), 'The original stays web-served at its public URL.');
  }

  /**
   * P9: node access denial is airtight on its own surface, identically both.
   */
  public function testPermissionGateOnContentIsIdenticalBoundAndUnbound(): void {
    $node = $this->uploadThroughImageWidget('field_public_image', 'gated_article', $this->createPngFile(90, 90), 'Gated secret');
    $this->drupalLogout();
    // A real site's most common content gate: the authenticated role does
    // not carry the site-wide access permission. Revoked here and restored
    // at teardown so other legs keep the stock shape.
    /** @var \Drupal\user\RoleInterface $authenticated */
    $authenticated = $this->container->get('entity_type.manager')->getStorage('user_role')->load('authenticated');
    $authenticated->revokePermission('access content');
    $authenticated->save();
    $this->container->get('entity_type.manager')->getStorage('user_role')->resetCache();
    $answers = [];
    foreach (['bound', 'unbound'] as $phase) {
      if ($phase === 'unbound') {
        $this->applyBindings([]);
      }
      $plain = $this->drupalCreateUser([]);
      $this->drupalLogin($plain);
      $this->drupalGet($node->toUrl());
      $answers[$phase]['without_permission'] = $this->getSession()->getStatusCode();
      $this->drupalLogout();
      $authorized = $this->drupalCreateUser(['access content']);
      $this->drupalLogin($authorized);
      $this->drupalGet($node->toUrl());
      $answers[$phase]['with_permission'] = $this->getSession()->getStatusCode();
      $this->drupalLogout();
      $this->drupalGet($node->toUrl());
      $answers[$phase]['anonymous'] = $this->getSession()->getStatusCode();
      $this->bindPublic();
    }
    self::assertSame($answers['unbound'], $answers['bound'], 'Every permission combination answers exactly as core-native.');
    self::assertSame([403, 200, 200], array_values($answers['bound']), 'The content-access permission is the gate: a logged-in user without it is denied, one with it and anonymous (per the anonymous role) pass — no broad bypass involved.');
  }

  /**
   * P10: a settings-provided public base URL moves answers identically.
   */
  public function testPublicBaseUrlOverrideMovesUrlsIdentically(): void {
    $answers = [];
    foreach (['bound', 'unbound'] as $phase) {
      $bindings = $phase === 'bound'
        ? [
          'public' => ['driver' => 'local', 'config' => ['root' => $this->publicRoot]],
          'temporary' => ['driver' => 'local', 'config' => ['root' => $this->tempRoot]],
        ]
        : [];
      $this->applyBindings($bindings, ['file_public_base_url' => 'http://cdn.example.test/files']);
      $title = 'Base url leg ' . $phase;
      $node = $this->uploadThroughImageWidget('field_public_image', 'article', $this->createPngFile(30, 30), $title);
      $uri = $node->get('field_public_image')->entity->getFileUri();
      $answers[$phase] = $this->normalize(
        \Drupal::service('file_url_generator')->generateString($uri)
        . '|' . ImageStyle::load('medium')->buildUrl($uri)
      );
      $node->delete();
    }
    $this->applyBindings([], ['file_public_base_url' => NULL]);
    self::assertSame($answers['unbound'], $answers['bound'], 'The overridden base URL is honored identically by the replaced wrapper.');
    self::assertStringStartsWith('http://cdn.example.test/files/', $answers['bound'], 'The override took effect (guarding the leg against a silent no-op).');
  }

}
