<?php

declare(strict_types=1);

namespace Drupal\flysystem\Plugin;

/**
 * Feature marker: this backend serves object keys on the local filesystem.
 *
 * Following the Search API model: a feature is declared by implementing
 * a tiny marker interface, not by describing capabilities in base-module
 * types. The settings validator's webroot-containment question is wired
 * through this marker — a publicly accessible binding backed by a
 * local-storage plugin must anchor inside the webroot, while plugins
 * without the marker (remote/URL backends) have no webroot concept and
 * are exempt. The marker carries no methods because no caller needs any
 * yet; adding one is an interface change.
 */
interface LocalStorageInterface {

}
