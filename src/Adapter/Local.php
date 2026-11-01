<?php

declare(strict_types=1);

namespace Drupal\flysystem\Adapter;

use Drupal\Core\Site\Settings;
use Drupal\flysystem\Util\LexicalPath;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use League\Flysystem\Visibility;

/**
 * The local filesystem adapter — the module's built-in backend.
 *
 * A container service registered in this module's services file and
 * tagged like any contributed adapter. It owns the root-handling rules
 * the local backend is responsible for: relative roots anchor to the
 * app root (never the process CWD), '..' segments collapse lexically,
 * no realpath() is performed anywhere (it would break not-yet-created
 * roots and resolve symlinks core leaves to the OS), and nothing
 * touches the filesystem at construction — the library's eager root
 * creation is explicitly disabled (the LocalFilesystemAdapter
 * constructor creates the root unless lazy root creation is set; core
 * never creates storage at config time either). The first write
 * materializes the root.
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
 * A missing 'root' surfaces as an argument error naming the problem;
 * a leading '../' in a target throws through the operator — the root
 * escape refusal is the library's guarantee, engaged by routing every
 * operation through the operator, never an emulated escape.
 */
class Local implements FlysystemAdapterInterface, LocalStorageInterface {

  /**
   * Constructs the local adapter service.
   *
   * @param string $appRoot
   *   The app root (webroot) relative configuration roots anchor against.
   */
  public function __construct(
    protected readonly string $appRoot,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function adapter(array $config): FilesystemAdapter {
    $root = $config['root'] ?? '';
    if (!is_string($root) || $root === '') {
      throw new \InvalidArgumentException('The local adapter requires a non-empty "root" configuration value.');
    }
    $resolved = LexicalPath::resolve($root, $this->appRoot);

    return new LocalFilesystemAdapter(
      $resolved,
      $this->visibilityConverter($config),
      \LOCK_EX,
      $this->linkHandling($config),
      lazyRootCreation: TRUE,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return class_exists(LocalFilesystemAdapter::class);
  }

  /**
   * Builds the file-permission converter: explicit config, else Drupal modes.
   *
   * @param array $config
   *   The merged adapter config.
   *
   * @return \League\Flysystem\UnixVisibility\PortableVisibilityConverter
   *   The converter to hand the library adapter.
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

}
