<?php

declare(strict_types=1);

namespace Drupal\flysystem\Resolution;

use Drupal\flysystem\Definition\AdapterDefinitionSourceInterface;
use Drupal\flysystem\Exception\UnknownAdapterDefinitionException;
use Drupal\flysystem\Validation\SettingsValidator;

/**
 * Resolves a scheme to its effective adapter definition, or nothing.
 *
 * Definition sources are consulted in the order given, highest precedence
 * first — normally settings.php, with config entity storage (when present)
 * layered below it. The rules:
 *
 * 1. A scheme bound in more than one source uses the highest-precedence
 *    binding, taken whole — bindings do not merge across sources.
 * 2. Adapter definitions deep-merge per leaf across all sources, the
 *    higher source winning each leaf; the winning binding's optional
 *    config overrides merge over its adapter definition on the same rule.
 * 3. A binding marked disabled => TRUE — and a scheme with no binding at
 *    all — resolves to NULL, meaning the scheme stays core-native.
 *
 * Resolution validates the touched scheme against the effective merged
 * layer through the injected validator, so a broken binding fails only
 * when something resolves through it — never at container build or
 * install. The validator's local-versus-remote answer comes from wiring
 * data (the plugin's declared features), not from running backend code.
 */
class BindingResolver {

  /**
   * Definition sources, highest precedence first.
   *
   * @var \Drupal\flysystem\Definition\AdapterDefinitionSourceInterface[]
   */
  protected $sources;

  /**
   * The settings validator applied to the touched scheme's layer.
   *
   * @var \Drupal\flysystem\Validation\SettingsValidator
   */
  protected $validator;

  /**
   * Constructs the resolver.
   *
   * @param \Drupal\flysystem\Definition\AdapterDefinitionSourceInterface[] $sources
   *   Definition sources ordered highest-precedence first.
   * @param \Drupal\flysystem\Validation\SettingsValidator $validator
   *   Validator instance wired with the plugin-locality answer.
   *
   * @throws \InvalidArgumentException
   *   When the source list is empty or a non-source is passed — wiring
   *   mistakes must fail loudly at build, not silently at resolve.
   */
  public function __construct(array $sources, SettingsValidator $validator) {
    if ($sources === []) {
      throw new \InvalidArgumentException('BindingResolver requires at least one definition source.');
    }
    foreach ($sources as $source) {
      if (!$source instanceof AdapterDefinitionSourceInterface) {
        throw new \InvalidArgumentException('Every definition source must implement AdapterDefinitionSourceInterface.');
      }
    }
    $this->sources = $sources;
    $this->validator = $validator;
  }

  /**
   * Resolves one scheme to its effective adapter definition, or NULL.
   *
   * @param string $scheme
   *   The scheme being resolved.
   *
   * @return array{plugin: string, config: array, publicly_accessible: bool}|null
   *   The merged definition to hand to the plugin, or NULL when the
   *   scheme resolves to core-native (unbound or disabled).
   *
   * @throws \Drupal\flysystem\Exception\InvalidSchemeNameException
   * @throws \Drupal\flysystem\Exception\InvalidAdapterIdException
   * @throws \Drupal\flysystem\Exception\InvalidSettingsStructureException
   * @throws \Drupal\flysystem\Exception\UnknownAdapterPluginException
   * @throws \Drupal\flysystem\Exception\PublicRootOutsideWebrootException
   * @throws \Drupal\flysystem\Exception\UnknownAdapterDefinitionException
   */
  public function resolve(string $scheme): ?array {
    $binding = NULL;
    foreach ($this->sources as $source) {
      $bindings = $source->getSchemeBindings();
      if (array_key_exists($scheme, $bindings)) {
        $binding = $bindings[$scheme];
        break;
      }
    }
    if (!is_array($binding)) {
      return NULL;
    }

    // Deep-merge adapter definitions across sources: lowest first so the
    // higher-precedence source wins every leaf.
    $adapters = [];
    foreach (array_reverse($this->sources) as $source) {
      $adapters = static::deepMerge($adapters, $source->getAdapterDefinitions());
    }

    // Validate the touched scheme against the effective layer, before any
    // use of the binding: a failure surfaces only when something resolves
    // through the broken scheme, never at container build or install.
    $this->validator->validateScheme($scheme, [
      'adapters' => $adapters,
      'bindings' => [$scheme => $binding],
    ]);

    if ($binding['disabled'] ?? FALSE) {
      return NULL;
    }

    $adapter_id = $binding['adapter'];
    $definition = $adapters[$adapter_id] ?? NULL;
    if (!is_array($definition)) {
      throw new UnknownAdapterDefinitionException($adapter_id, $scheme);
    }

    $config = static::deepMerge(
      is_array($definition['config'] ?? NULL) ? $definition['config'] : [],
      is_array($binding['config'] ?? NULL) ? $binding['config'] : [],
    );

    return [
      'plugin' => $definition['plugin'],
      'config' => $config,
      'publicly_accessible' => $binding['publicly_accessible'] ?? ($scheme === SettingsValidator::PUBLIC_SCHEME),
    ];
  }

  /**
   * Recursively merges override leaves over base leaves.
   *
   * Arrays merge per key; every other value (scalars, objects, lists
   * wholesale) replaces. Highest-precedence data must always win a leaf.
   *
   * @param array $base
   *   The lower-precedence layer.
   * @param array $override
   *   The higher-precedence layer.
   *
   * @return array
   *   The merged result.
   */
  protected static function deepMerge(array $base, array $override): array {
    foreach ($override as $key => $value) {
      if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && !self::isList($value) && !self::isList($base[$key])) {
        $base[$key] = static::deepMerge($base[$key], $value);
        continue;
      }
      $base[$key] = $value;
    }
    return $base;
  }

  /**
   * TRUE for sequential-list arrays, which merge as a whole, not per leaf.
   */
  private static function isList(array $array): bool {
    return array_is_list($array);
  }

}
