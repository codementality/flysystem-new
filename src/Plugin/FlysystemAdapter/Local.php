<?php

declare(strict_types=1);

namespace Drupal\flysystem\Plugin\FlysystemAdapter;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\flysystem\Attribute\FlysystemAdapter;
use Drupal\flysystem\Plugin\FlysystemAdapterBase;
use Drupal\flysystem\Plugin\LocalStorageInterface;
use Drupal\flysystem\Util\LexicalPath;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The local filesystem adapter plugin — the one user-definable backend of M1.
 *
 * Owns the root-handling rules the local backend is responsible for:
 * relative roots anchor to the app root (never the process CWD), '..'
 * segments collapse lexically, no realpath, and NO filesystem side effect
 * at construction or operator build — the library's eager root creation
 * is explicitly disabled (LocalFilesystemAdapter constructor creates the
 * root unless lazyRootCreation is set; core never creates storage at
 * config time either). The first write materializes the root.
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

    $visibility = NULL;
    if (isset($config['unix_visibility']) && is_array($config['unix_visibility'])) {
      $visibility = PortableVisibilityConverter::fromArray($config['unix_visibility']);
    }

    return new Filesystem(new LocalFilesystemAdapter(
      $resolved,
      $visibility,
      lazyRootCreation: TRUE,
    ));
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return class_exists(LocalFilesystemAdapter::class);
  }

}
