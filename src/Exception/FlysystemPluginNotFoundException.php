<?php

declare(strict_types=1);

namespace Drupal\flysystem\Exception;

use Drupal\Component\Plugin\Exception\PluginNotFoundException;

/**
 * Thrown when a requested adapter plugin id does not exist.
 *
 * Typed and catchable by name by factory/resolver consumers; still a core
 * PluginNotFoundException for anything catching the generic type.
 */
class FlysystemPluginNotFoundException extends PluginNotFoundException {

}
