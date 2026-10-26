<?php

declare(strict_types=1);

namespace Drupal\flysystem\Adapter;

/**
 * How a bound scheme is registered with PHP's stream machinery.
 *
 * Local-mode adapters serve object keys over a filesystem-like store;
 * Url-mode adapters serve through URLs (remote/external posture — the D9
 * webroot containment rule exempts them because they have no webroot
 * concept). One field, three consumers in M1/M2: wrapper registration
 * (evaluation §1.1/§1.4), operator traversal posture (B2), and binding
 * validation (M1-05).
 */
enum RegistrationMode {

  // Filesystem-style object-key storage (local disk, in-memory stores).
  case Local;

  // URL-addressed storage; schemes register with STREAM_IS_URL semantics.
  case Url;

}
