<?php

declare(strict_types=1);

namespace Drupal\flysystem\Plugin\FlysystemAdapter;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\flysystem\Adapter\FlysystemAdapterBase;
use Drupal\flysystem\Adapter\LocalStorageInterface;
use Drupal\flysystem\Attribute\FlysystemAdapter;
use Drupal\flysystem\Util\LexicalPath;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use League\Flysystem\Visibility;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The local filesystem adapter plugin — the module's built-in backend.
 *
 * Registered through the adapter attribute like any contributed
 * backend, so nothing in this module is special-cased. The plugin owns
 * the root-handling rules local storage is responsible for: relative
 * roots anchor to the app root (never the process CWD), '..' segments
 * collapse lexically, no realpath() is performed anywhere (it would
 * break not-yet-created roots and resolve symlinks core leaves to the
 * OS), and nothing touches the filesystem at construction — the
 * library's eager root creation is explicitly disabled (the
 * LocalFilesystemAdapter constructor creates the root unless lazy root
 * creation is set; core never creates storage at config time either).
 * The first write materializes the root.
 *
 * Permission posture (backward compatibility with the Drupal Flysystem
 * 2.x module): users are not prompted for unix modes. Defaults come
 * from Drupal's own long-standing settings — file_chmod_file (0664)
 * and file_chmod_directory (0775), with private counterparts 0600 and
 * 0700. Per-binding overrides: 'unix_visibility' (league v3 map shape)
 * and its 2.x-era alias 'permissions'. Operation defaults (visibility,
 * traversal posture) are assembled by the operator factory, not here:
 * every backend shares one assembly path.
 *
 * A missing 'root' surfaces as an argument error naming the problem; a
 * leading '../' in a target throws through the operator — the root
 * escape refusal is the library's guarantee, engaged by routing every
 * operation through the operator, never an emulated escape.
 */
#[FlysystemAdapter(
  id: 'local',
  label: new TranslatableMarkup('Local files'),
)]
final class Local extends FlysystemAdapterBase implements LocalStorageInterface, ContainerFactoryPluginInterface {

  /**
   * The league adapter object, built once for this plugin's config.
   *
   * @var \League\Flysystem\FilesystemAdapter|null
   */
  protected ?FilesystemAdapter $leagueAdapter = NULL;

  /**
   * Constructs the local adapter plugin.
   *
   * @param string $appRoot
   *   The app root (webroot) relative configuration roots anchor
   *   against.
   * @param array $configuration
   *   The merged binding configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The discovered plugin definition.
   */
  public function __construct(
    protected readonly string $appRoot,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    return new static(
      (string) $container->getParameter('app.root'),
      $configuration,
      $plugin_id,
      $plugin_definition,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function adapter(): FilesystemAdapter {
    if ($this->leagueAdapter === NULL) {
      $root = $this->configuration['root'] ?? '';
      if (!is_string($root) || $root === '') {
        throw new \InvalidArgumentException('The local adapter requires a non-empty "root" configuration value.');
      }
      $resolved = LexicalPath::resolve($root, $this->appRoot);

      $this->leagueAdapter = new LocalFilesystemAdapter(
        $resolved,
        $this->visibilityConverter(),
        \LOCK_EX,
        $this->linkHandling(),
        lazyRootCreation: TRUE,
      );
    }
    return $this->leagueAdapter;
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return class_exists(LocalFilesystemAdapter::class);
  }

  /**
   * Builds the file-permission converter: explicit config, else modes.
   *
   * @return \League\Flysystem\UnixVisibility\PortableVisibilityConverter
   *   The converter to hand the library adapter.
   */
  protected function visibilityConverter(): PortableVisibilityConverter {
    $map = $this->configuration['unix_visibility']
      ?? $this->configuration['permissions']
      ?? NULL;
    if (!is_array($map)) {
      // Defaults are Drupal's own settings values, read the same way
      // core reads them (pre-container), so a site carries its
      // long-standing umask-independent expectations without
      // configuring per scheme.
      $map = [
        'file' => [
          'public' => (int) Settings::get('file_chmod_file', 0664),
          'private' => 0600,
        ],
        'dir' => [
          'public' => (int) Settings::get('file_chmod_directory', 0775),
          'private' => 0700,
        ],
      ];
    }
    // Implicitly created directories take the public mode, matching
    // core's mkdir behavior; league's fromArray would otherwise default
    // directories to PRIVATE.
    return PortableVisibilityConverter::fromArray($map, Visibility::PUBLIC);
  }

  /**
   * Maps 'link_handling' config to the library's link-handling modes.
   *
   * @return int
   *   SKIP_LINKS when configured 'skip', otherwise DISALLOW_LINKS.
   */
  protected function linkHandling(): int {
    return ($this->configuration['link_handling'] ?? 'disallow') === 'skip'
      ? LocalFilesystemAdapter::SKIP_LINKS
      : LocalFilesystemAdapter::DISALLOW_LINKS;
  }

}
