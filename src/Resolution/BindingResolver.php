<?php

declare(strict_types=1);

namespace Drupal\flysystem\Resolution;

use Drupal\flysystem\Definition\AdapterDefinitionSourceInterface;
use Drupal\flysystem\Exception\UnknownAdapterDefinitionException;
use Drupal\flysystem\Validation\SettingsValidator;

/**
 * The single implementation of scheme binding precedence (D4/D8).
 *
 * Sources are consulted in the order given, highest precedence first —
 * production passes settings.php then config entities (M3 plugs in as
 * another source; nothing below the resolver can tell them apart, which
 * is issue #4's seam). Rules, exactly as the row states them:
 *
 * 1. A scheme bound in more than one source uses the HIGHEST binding,
 *    replaced whole — bindings do not merge across sources.
 * 2. Adapter definitions DO deep-merge per leaf across all sources, with
 *    the higher source winning each leaf; the winning binding's optional
 *    config overrides merge over its adapter on the same rule.
 * 3. disabled => TRUE resolves the scheme to core-native (NULL),
 *    as does having no binding at all (D7a coexistence).
 *
 * Resolution validates the touched scheme through #5's validator against
 * the EFFECTIVE merged layer, keeping B4 lazy timing and the D9 answer
 * honest: a binding only fails when something resolves through it, and
 * plugin-locality comes from the caller's validator wiring (marker
 * interface at discovery data), never from backend code execution.
 */
class BindingResolver {

  /**
   * @var \Drupal\flysystem\Definition\AdapterDefinitionSourceInterface[]
   *   Sources in precedence order, highest first.
   */
  protected $sources;

  /**
   * @var \Drupal\flysystem\Validation\SettingsValidator
   *   The settings validator applied to the touched scheme's layer.
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
    // use of the binding (B4: this only fires for schemes being resolved).
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
