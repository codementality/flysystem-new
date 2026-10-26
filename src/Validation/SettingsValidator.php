<?php

declare(strict_types=1);

namespace Drupal\flysystem\Validation;

use Drupal\flysystem\Exception\InvalidAdapterIdException;
use Drupal\flysystem\Exception\InvalidSchemeNameException;
use Drupal\flysystem\Exception\InvalidSettingsStructureException;
use Drupal\flysystem\Exception\PublicRootOutsideWebrootException;
use Drupal\flysystem\Exception\UnknownAdapterPluginException;

/**
 * Validates the settings.php flysystem layer ($settings['flysystem']).
 *
 * B4 lazy timing: validateScheme() touches one scheme and surfaces only
 * that scheme's defects; broken bindings elsewhere never fire while an
 * unrelated scheme is checked. assertValidStructure() is the full sweep
 * for verification tooling (M1-11). Neither runs at container build.
 *
 * D9 containment: a publicly accessible binding backed by a local-mode
 * plugin must anchor its root inside the app root. Roots are anchored
 * against the app root and collapsed lexically — never realpath()-ed
 * (B2a: realpath would break not-yet-created roots and resolve symlinks
 * core leaves to the OS). Remote plugins are exempt: served by external
 * URL, they have no webroot concept.
 *
 * Plugin locality enters through an injectable predicate (plugin id =>
 * TRUE local, FALSE remote, NULL unknown). The M1-02 plugin manager
 * bridges this to M1-03's RegistrationMode enum at wiring time; the
 * un-injected default recognizes 'local' only.
 */
final class SettingsValidator {

  /**
   * Scheme grammar: the intersection of PHP-registration and core-parsing.
   *
   * PHP's stream-wrapper registration rejects underscores outright (verified
   * live: "Invalid protocol scheme specified"); core's scheme parser
   * (web/core/lib/Drupal/Core/StreamWrapper/StreamWrapperManager.php:
   * 269-276) cannot parse '.' or '+'. A bindable scheme must exist in
   * both worlds: letters, digits, and '-' only.
   */
  public const SCHEME_PATTERN = '/^[A-Za-z0-9-]+$/';

  /**
   * Adapter id grammar: lowercase alphanumeric plus underscore.
   */
  public const ADAPTER_ID_PATTERN = '/^[a-z0-9_]+$/';

  /**
   * The only core scheme that defaults publicly_accessible to TRUE.
   */
  public const PUBLIC_SCHEME = 'public';

  /**
   * The app root (webroot) for anchoring and D9 containment.
   */
  private readonly string $appRoot;

  /**
   * Plugin locality predicate: id => ?bool (NULL = unknown plugin).
   */
  private readonly \Closure $isLocalMode;

  /**
   * Constructs the validator.
   *
   * @param string $appRoot
   *   The app root (webroot), e.g. \Drupal::root() at wiring time. Used
   *   for anchoring relative roots and as the D9 containment boundary.
   *   Trailing separators are normalized away.
   * @param callable|null $isLocalMode
   *   (optional) Predicate mapping a plugin id to TRUE (local-mode),
   *   FALSE (remote/URL-mode), or NULL (unknown plugin). Defaults to a
   *   predicate recognizing only 'local'.
   */
  public function __construct(string $appRoot, ?callable $isLocalMode = NULL) {
    $this->appRoot = rtrim($appRoot, '/');
    $this->isLocalMode = $isLocalMode === NULL
      ? static fn (string $pluginId): ?bool => $pluginId === 'local' ? TRUE : NULL
      : \Closure::fromCallable($isLocalMode);
  }

  /**
   * Validates everything the given scheme touches; nothing else.
   *
   * @param string $scheme
   *   The scheme being resolved (M1-06 calls this on touch).
   * @param array $layer
   *   The raw flysystem layer.
   *
   * @throws \Drupal\flysystem\Exception\InvalidSchemeNameException
   *   When the scheme name is outside core's grammar.
   * @throws \Drupal\flysystem\Exception\InvalidAdapterIdException
   *   When the binding's adapter id is outside the declared grammar.
   * @throws \Drupal\flysystem\Exception\InvalidSettingsStructureException
   *   When a touched section or entry has the wrong shape.
   * @throws \Drupal\flysystem\Exception\UnknownAdapterPluginException
   *   When the referenced plugin id is not a known adapter plugin.
   * @throws \Drupal\flysystem\Exception\PublicRootOutsideWebrootException
   *   When a publicly accessible local root resolves outside the webroot (D9).
   */
  public function validateScheme(string $scheme, array $layer): void {
    if (!preg_match(self::SCHEME_PATTERN, $scheme)) {
      throw new InvalidSchemeNameException($scheme);
    }

    if (array_key_exists('bindings', $layer) && !is_array($layer['bindings'])) {
      throw new InvalidSettingsStructureException('bindings', 'an array keyed by scheme');
    }
    $binding = $layer['bindings'][$scheme] ?? NULL;
    if ($binding === NULL) {
      return;
    }

    $path = sprintf('bindings[%s]', $scheme);
    if (!is_array($binding)) {
      throw new InvalidSettingsStructureException($path, 'an array with an adapter key');
    }
    $this->validateBindingShape($path, $binding);

    if ($binding['disabled'] ?? FALSE) {
      return;
    }

    $publiclyAccessible = $binding['publicly_accessible'] ?? ($scheme === self::PUBLIC_SCHEME);

    $adapterId = $binding['adapter'];
    if (array_key_exists('adapters', $layer) && !is_array($layer['adapters'])) {
      throw new InvalidSettingsStructureException('adapters', 'an array keyed by adapter id');
    }
    $definition = $layer['adapters'][$adapterId] ?? NULL;
    if ($definition === NULL) {
      // Binding to an undefined adapter is a resolution concern (M1-06),
      // not a settings-shape violation.
      return;
    }

    $defPath = sprintf('adapters[%s]', $adapterId);
    if (!is_array($definition)) {
      throw new InvalidSettingsStructureException($defPath, 'an array with a plugin key');
    }
    $this->validateDefinitionShape($defPath, $definition);

    $plugin = $definition['plugin'];
    $isLocal = ($this->isLocalMode)($plugin);
    if ($isLocal === NULL) {
      throw new UnknownAdapterPluginException($plugin, $adapterId);
    }

    if ($isLocal && $publiclyAccessible) {
      $root = $definition['config']['root'] ?? NULL;
      if ($root === NULL) {
        throw new PublicRootOutsideWebrootException($scheme, '(no root configured)', $this->appRoot);
      }
      $resolved = $this->resolveRoot($root);
      if (!$this->isInsideAppRoot($resolved)) {
        throw new PublicRootOutsideWebrootException($scheme, $resolved, $this->appRoot);
      }
    }
  }

  /**
   * Full-layer structural sweep: every section, entry, and name.
   *
   * Does not run D9 containment (that is inherently per-scheme and lazy);
   * it answers "is this layer structurally sane everywhere" for the
   * verification API (M1-11).
   *
   * @param array $layer
   *   The raw flysystem layer.
   *
   * @throws \Drupal\flysystem\Exception\InvalidSettingsStructureException
   *   When a section or entry has the wrong shape.
   * @throws \Drupal\flysystem\Exception\InvalidSchemeNameException
   *   When a binding key is outside the scheme grammar.
   * @throws \Drupal\flysystem\Exception\InvalidAdapterIdException
   *   When an adapter key is outside the adapter id grammar.
   */
  public function assertValidStructure(array $layer): void {
    foreach (['adapters', 'bindings'] as $section) {
      if (array_key_exists($section, $layer) && !is_array($layer[$section])) {
        $keyName = $section === 'adapters' ? 'adapter' : 'scheme';
        throw new InvalidSettingsStructureException($section, sprintf('an array keyed by %s id', $keyName));
      }
    }

    foreach ((array) ($layer['bindings'] ?? []) as $scheme => $binding) {
      if (!preg_match(self::SCHEME_PATTERN, (string) $scheme)) {
        throw new InvalidSchemeNameException((string) $scheme);
      }
      $path = sprintf('bindings[%s]', $scheme);
      if (!is_array($binding)) {
        throw new InvalidSettingsStructureException($path, 'an array with an adapter key');
      }
      $this->validateBindingShape($path, $binding);
    }

    foreach ((array) ($layer['adapters'] ?? []) as $adapterId => $definition) {
      if (!preg_match(self::ADAPTER_ID_PATTERN, (string) $adapterId)) {
        throw new InvalidAdapterIdException((string) $adapterId);
      }
      $defPath = sprintf('adapters[%s]', $adapterId);
      if (!is_array($definition)) {
        throw new InvalidSettingsStructureException($defPath, 'an array with a plugin key');
      }
      $this->validateDefinitionShape($defPath, $definition);
    }
  }

  /**
   * Checks binding entry types (adapter string plus optional typed flags).
   *
   * @param string $path
   *   The settings path prefix for error messages.
   * @param array $binding
   *   The binding entry.
   */
  private function validateBindingShape(string $path, array $binding): void {
    if (!isset($binding['adapter']) || !is_string($binding['adapter'])) {
      throw new InvalidSettingsStructureException($path . '[adapter]', 'a string adapter id');
    }
    if (!preg_match(self::ADAPTER_ID_PATTERN, $binding['adapter'])) {
      throw new InvalidAdapterIdException($binding['adapter']);
    }
    if (array_key_exists('config', $binding) && !is_array($binding['config'])) {
      throw new InvalidSettingsStructureException($path . '[config]', 'an array of definition overrides');
    }
    if (array_key_exists('disabled', $binding) && !is_bool($binding['disabled'])) {
      throw new InvalidSettingsStructureException($path . '[disabled]', 'a boolean');
    }
    if (array_key_exists('publicly_accessible', $binding) && !is_bool($binding['publicly_accessible'])) {
      throw new InvalidSettingsStructureException($path . '[publicly_accessible]', 'a boolean');
    }
  }

  /**
   * Checks adapter definition entry types (plugin string, optional config).
   *
   * @param string $path
   *   The settings path prefix for error messages.
   * @param array $definition
   *   The adapter definition entry.
   */
  private function validateDefinitionShape(string $path, array $definition): void {
    if (!isset($definition['plugin']) || !is_string($definition['plugin'])) {
      throw new InvalidSettingsStructureException($path . '[plugin]', 'a string plugin id');
    }
    if (array_key_exists('config', $definition) && !is_array($definition['config'])) {
      throw new InvalidSettingsStructureException($path . '[config]', 'an array');
    }
    if (isset($definition['config']['root']) && !is_string($definition['config']['root'])) {
      throw new InvalidSettingsStructureException($path . '[config][root]', 'a string root path');
    }
  }

  /**
   * Anchors a configured root against the app root and collapses segments.
   *
   * Lexical only (B2a): no realpath(), no filesystem access, no side
   * effects. Absolute roots pass through normalization unchanged apart
   * from '..' collapsing.
   *
   * @param string $root
   *   The configured root.
   *
   * @return string
   *   The resolved absolute root path.
   */
  private function resolveRoot(string $root): string {
    $anchored = str_starts_with($root, '/') ? $root : $this->appRoot . '/' . $root;
    $segments = [];
    foreach (explode('/', $anchored) as $segment) {
      if ($segment === '' || $segment === '.') {
        continue;
      }
      if ($segment === '..') {
        if ($segments && end($segments) !== '..') {
          array_pop($segments);
        }
        else {
          $segments[] = '..';
        }
        continue;
      }
      $segments[] = $segment;
    }
    return '/' . implode('/', $segments);
  }

  /**
   * TRUE when the resolved path is the app root or below it.
   *
   * @param string $resolved
   *   The resolved root path.
   *
   * @return bool
   *   Whether containment inside the webroot holds.
   */
  private function isInsideAppRoot(string $resolved): bool {
    return $resolved === $this->appRoot || str_starts_with($resolved, $this->appRoot . '/');
  }

}
