<?php

declare(strict_types=1);

namespace Drupal\flysystem\Plugin\FlysystemAdapter;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\flysystem\Attribute\FlysystemAdapter;
use Drupal\flysystem\Plugin\FlysystemAdapterBase;
use Drupal\flysystem\Plugin\LocalStorageInterface;
use Drupal\flysystem\Util\LexicalPath;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use League\Flysystem\Visibility;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The local filesystem adapter plugin — the module's built-in backend.
 *
 * Owns the root-handling rules the local backend is responsible for:
 * relative roots anchor to the app root (never the process CWD), '..'
 * segments collapse lexically, no realpath, and NO filesystem side effect
 * at construction or operator build — the library's eager root creation
 * is explicitly disabled (LocalFilesystemAdapter constructor creates the
 * root unless lazyRootCreation is set; core never creates storage at
 * config time either). The first write materializes the root.
 *
 * Permission posture (backward compatibility with the Drupal Flysystem
 * 2.x module): users are NOT prompted for unix modes. Defaults come
 * from Drupal's own long-standing settings — file_chmod_file (0664) and
 * file_chmod_directory (0775) — with private counterparts 0600/0700, and
 * a Filesystem-level default visibility of PUBLIC so writes are chmodded
 * to the Drupal mode (library default converter mode 0644 is not used).
 * Per-binding overrides: 'unix_visibility' (league v3 map shape), its
 * 2.x-era alias 'permissions', and 'visibility'/'directory_visibility'
 * operation defaults. 'link_handling' => 'skip' opts into skipping
 * symlinked entries in listings; the default disallows them (library
 * default preserved).
 *
 * A missing 'root' at write time surfaces the library's typed
 * UnableToWriteFile with path context; a leading '../' in a target throws
 * PathTraversalDetected through the operator — the documented deviation
 * posture, never an emulated escape.
 */
#[FlysystemAdapter(id: 'local', label: new TranslatableMarkup('Local files'))]
class Local extends FlysystemAdapterBase implements LocalStorageInterface, ContainerFactoryPluginInterface {

  /**
   * The app root relative configuration roots are anchored against.
   *
   * @var string
   */
  protected $appRoot;

  /**
   * Constructs the local adapter plugin.
   *
   * @param array $configuration
   *   Plugin configuration (root, optional unix_visibility).
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param string $appRoot
   *   The app root to anchor relative configuration roots against.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    string $appRoot,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->appRoot = $appRoot;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      (string) $container->getParameter('app.root'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildFilesystem(array $config = []): FilesystemOperator {
    $config += $this->getConfiguration();
    $root = $config['root'] ?? '';
    if (!is_string($root) || $root === '') {
      throw new \InvalidArgumentException('The local adapter requires a non-empty "root" configuration value.');
    }
    $resolved = LexicalPath::resolve($root, $this->appRoot);

    return new Filesystem(
      new LocalFilesystemAdapter(
        $resolved,
        $this->visibilityConverter($config),
        \LOCK_EX,
        $this->linkHandling($config),
        lazyRootCreation: TRUE,
      ),
      $this->defaultOperationConfig($config),
    );
  }

  /**
   * Builds the file-permission converter: explicit config, else Drupal modes.
   *
   * @param array $config
   *   The merged adapter config.
   *
   * @return \League\Flysystem\UnixVisibility\PortableVisibilityConverter
   *   The converter to hand the library.
   */
  protected function visibilityConverter(array $config): PortableVisibilityConverter {
    $map = $config['unix_visibility'] ?? $config['permissions'] ?? NULL;
    if (!is_array($map)) {
      // Defaults are Drupal's own settings values, read the same way core
      // reads them (pre-container), so a site carries its long-standing
      // umask-independent expectations without configuring per scheme.
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
   * Maps 'link_handling' config to the library's link-handling constants.
   *
   * @param array $config
   *   The merged adapter config.
   *
   * @return int
   *   SKIP_LINKS when configured 'skip', otherwise DISALLOW_LINKS.
   */
  protected function linkHandling(array $config): int {
    return ($config['link_handling'] ?? 'disallow') === 'skip'
      ? LocalFilesystemAdapter::SKIP_LINKS
      : LocalFilesystemAdapter::DISALLOW_LINKS;
  }

  /**
   * Operator-level defaults merged into every operation's config.
   *
   * Default visibility PUBLIC is what makes the file mode actually apply
   * on writes (the library only chmods when visibility is present).
   *
   * @param array $config
   *   The merged adapter config.
   *
   * @return array<string, string>
   *   Default operation config.
   */
  protected function defaultOperationConfig(array $config): array {
    $visibility = $config['visibility'] ?? Visibility::PUBLIC;
    $defaults = ['visibility' => $visibility];
    if (isset($config['directory_visibility'])) {
      $defaults['directory_visibility'] = (string) $config['directory_visibility'];
    }
    if (array_key_exists('allow_relative_path_traversal', $config)) {
      // The factory's posture decision rides in the merged configuration
      // under the library's own key; forwarded untouched to the operator.
      $defaults['allow_relative_path_traversal'] = (bool) $config['allow_relative_path_traversal'];
    }
    return $defaults;
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return class_exists(LocalFilesystemAdapter::class);
  }

}
