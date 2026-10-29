<?php

declare(strict_types=1);

namespace Drupal\flysystem\Tests\Functional;

use Drupal\image\Entity\ImageStyle;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
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
    $this->publicRoot = 'flysystem-e2e-public-' . $token;
    $this->tempRoot = 'flysystem-e2e-temp-' . $token;
    foreach ([$this->publicRoot, $this->tempRoot] as $dir) {
      if (!is_dir(DRUPAL_ROOT . '/' . $dir)) {
        mkdir(DRUPAL_ROOT . '/' . $dir, 0777, TRUE);
      }
    }
    $this->bindPublic();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $root = DRUPAL_ROOT;
    $public = $this->publicRoot;
    $temp = $this->tempRoot;
    parent::tearDown();
    foreach ([$public, $temp] as $dir) {
      $this->rrmdir($root . '/' . $dir);
    }
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
    $kernel = $this->container->get('kernel');
    $this->container = $kernel->rebuildContainer();
    \Drupal::setContainer($this->container);
    $this->container->get('stream_wrapper_manager')->register();
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
   * Strips per-run randomness out of text so bound and unbound compare.
   *
   * @param string $text
   *   Markup, URI, or URL text from this run.
   *
   * @return string
   *   The same text with the random directory tokens removed.
   */
  protected function normalize(string $text): string {
    return str_replace(
      [$this->publicRoot, $this->tempRoot, $this->siteDirectory],
      ['PUBLICROOT', 'TEMPROOT', 'SITEDIR'],
      $text
    );
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
    $page->attachFileToField($fieldName . '[0][value]', $source);
    $page->pressButton($fieldName . '[0][upload_button]');
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
    $page->attachFileToField('field_media_image[0][value]', $this->createPngFile(64, 64));
    $page->pressButton('field_media_image[0][upload_button]');
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
    self::assertStringStartsWith('public://media/', $uri);

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
      $src = (string) $this->cssSelect('.field--name-field-public-image img')[0]->getAttribute('src');
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
    $src = (string) $this->cssSelect('.field--name-field-public-image img')[0]->getAttribute('src');
    self::assertSame(ImageStyle::load('medium')->buildUrl($uri), $src);
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
    $src = (string) $this->cssSelect('.field--name-field-public-image img')[0]->getAttribute('src');
    $this->drupalGet($src);
    self::assertSame(200, $this->getSession()->getStatusCode());
    self::assertFileExists($physical, 'Serving the style URL generated the derivative inside the bound root.');
    $stat = @getimagesize($physical);
    self::assertSame(100, $stat[0], 'The thumbnail effect applied through the bound write path.');
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
    self::assertSame('styles/m211_scale/' . substr($uri, \strlen('public://')), explode('?', $suffix)[0], 'The rendered style target addresses exactly the placed object.');
    $this->drupalGet($this->container->get('file_url_generator')->transformRelative($m[1]));
    self::assertSame(200, $this->getSession()->getStatusCode());
    $image = imagecreatefromstring((string) $this->getSession()->getPage()->getContent());
    self::assertNotFalse($image);
    $dims = [imagesx($image), imagesy($image)];
    imagedestroy($image);
    self::assertSame([50, 50], $dims, 'The served bytes are the 50px scaled derivative, not the 120px original.');
  }

  /**
   * P7: after a style flush the derivative regenerates into the bound root.
   */
  public function testDerivativeRegeneratesAfterFlush(): void {
    $node = $this->uploadThroughImageWidget('field_public_image', 'article', $this->createPngFile(140, 140), 'Flush leg');
    $uri = $node->get('field_public_image')->entity->getFileUri();
    $style = ImageStyle::load('medium');
    $this->drupalGet($node->toUrl());
    $src = (string) $this->cssSelect('.field--name-field-public-image img')[0]->getAttribute('src');
    $this->drupalGet($src);
    self::assertSame(200, $this->getSession()->getStatusCode());
    $derivativeUri = $style->buildUri($uri);
    $physical = DRUPAL_ROOT . '/' . $this->publicRoot . '/' . substr($derivativeUri, \strlen('public://'));
    self::assertFileExists($physical);

    $style->flush();
    self::assertFileDoesNotExist($physical, 'The flush cleared the derivative from the bound root.');
    $this->drupalGet($style->buildUrl($uri) . '?itok=' . $style->getPathToken($uri));
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
    $src = (string) $this->cssSelect('.field--name-field-public-image img')[0]->getAttribute('src');
    $this->drupalGet($src);
    self::assertSame(200, $this->getSession()->getStatusCode());
    $this->drupalGet($this->urlFor($uri));
    self::assertSame(200, $this->getSession()->getStatusCode(), 'The original stays web-served at its public URL.');
  }

  /**
   * P9: node access denial is airtight on its own surface, identically both.
   */
  public function testPermissionGateOnContentIsIdenticalBoundAndUnbound(): void {
    $node = $this->uploadThroughImageWidget('field_public_image', 'gated_article', $this->createPngFile(90, 90), 'Gated secret');
    $node->set('status', FALSE)->save();
    $answers = [];
    foreach (['bound', 'unbound'] as $phase) {
      if ($phase === 'unbound') {
        $this->applyBindings([]);
      }
      $this->drupalLogout();
      $this->drupalGet($node->toUrl());
      $answers[$phase]['anonymous_node'] = $this->getSession()->getStatusCode();
      $limited = $this->drupalCreateUser(['access content']);
      $this->drupalLogin($limited);
      $this->drupalGet($node->toUrl());
      $answers[$phase]['unprivileged_node'] = $this->getSession()->getStatusCode();
      $privileged = $this->drupalCreateUser([
        'access content',
        'view any unpublished content',
        'bypass node access',
      ]);
      $this->drupalLogin($privileged);
      $this->drupalGet($node->toUrl());
      $answers[$phase]['privileged_node'] = $this->getSession()->getStatusCode();
      $this->drupalLogout();
      $this->bindPublic();
    }
    self::assertSame($answers['unbound'], $answers['bound'], 'Every permission combination answers exactly as core-native.');
    self::assertSame([403, 403, 200], array_values($answers['bound']), 'Unpublished content is denied to anonymous and unprivileged users and allowed to privileged ones.');
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
