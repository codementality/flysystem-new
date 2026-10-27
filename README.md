# Flysystem

Flysystem-backed file storage for Drupal, built on the League/Flysystem v3
library. This module introduces an adapter plugin system: storage backends
are discoverable plugins that bind to stream wrappers (`public://`,
`private://`, `temporary://`, or any new scheme you define) through
`settings.php`.

## Configuration

Adapter definitions and scheme bindings live under
`$settings['flysystem']` in `settings.php`:

```php
$settings['flysystem'] = [
  'adapters' => [
    'main' => [
      'plugin' => 'local',
      'config' => [
        'root' => 'sites/default/files',
      ],
    ],
    'secure' => [
      'plugin' => 'local',
      'config' => [
        // Relative roots anchor against the Drupal install root, never
        // the current working directory of the running process.
        'root' => '../private-files',
      ],
    ],
  ],
  'bindings' => [
    'public' => ['adapter' => 'main'],
    'private' => ['adapter' => 'secure'],
    // A settings.php binding replaces any entity-stored binding for the
    // same scheme, and 'disabled' => TRUE pins a scheme to core's native
    // stream wrapper regardless of other configuration sources.
    'temporary' => ['adapter' => 'main', 'disabled' => TRUE],
  ],
];
```

Binding options:

- `adapter` (required): the adapter id defined in `adapters`.
- `config` (optional): values merged over the adapter definition, per key.
- `disabled` (optional, default `FALSE`): resolves the scheme back to
  core-native storage.
- `publicly_accessible` (optional): whether the scheme serves files over
  web URLs. Defaults to `TRUE` only for `public`; every other scheme
  defaults to `FALSE`. When `TRUE` on a locally bound scheme, the root
  must resolve inside the webroot — publicly reachable storage outside
  the document root cannot be served by URL and is rejected with an
  actionable error naming the resolved path. Remote adapters are exempt:
  they have no webroot concept.

Scheme names must match `^[A-Za-z0-9-]+$` (letters, digits, and hyphens).
PHP itself cannot register stream wrappers whose names contain
underscores, and characters such as dots and pluses are not understood
by Drupal's scheme parser; both are rejected when a scheme is used, with
precise errors raised at that moment rather than at container build.

Local adapter options:

- `root` (required): absolute path, or relative to the Drupal install
  root. The directory is created on the first write, never at
  configuration time.
- `unix_visibility` (optional): file and directory permission map —
  `['file' => ['public' => 0664, 'private' => 0600], 'dir' => ...]`.
  Defaults are taken from the site's own `file_chmod_file` and
  `file_chmod_directory` settings, so the normal Drupal permission model
  applies without any per-scheme configuration.
- `link_handling` (optional): `disallow` (default) makes listed storage
  traversal refuse symlinks; `skip` omits symlinked entries from
  listings.

## Upgrading from the 2.x module (legacy settings shape)

The previous major version of this module used a different
`settings.php` layout: one entry per scheme, keyed by the scheme name,
each carrying a `driver` and a `config` array. That shape continues to
work unchanged — the settings source detects it and presents it through
the same internal layers the modern shape uses:

```php
// Accepted (legacy) — read by upgrading sites without edits.
$settings['flysystem'] = [
  'myfiles' => [
    'driver' => 'local',
    'config' => [
      'root' => 'sites/default/files/myfiles',
      'public' => TRUE,
    ],
  ],
];
```

Legacy entries map as follows:

- The scheme key becomes a binding for that scheme; the sanitized
  scheme name becomes its adapter id.
- `driver` becomes the plugin id; `config` passes through untouched.
- A boolean `config['public']` flag becomes the binding's
  `publicly_accessible` value.

### Configuration options that do not carry forward

The 2.x module sat on League/Flysystem v1 and carried settings for
features that either moved elsewhere or have no equivalent in v3. They
are accepted and ignored (an unrecognized key is inert data, never an
error), but they do nothing:

- **`replicate` — deprecated.** v1's replication adapter (write each
  file to a second endpoint as a backup) has no League/Flysystem v3
  equivalent and is not reimplemented here. Sites relying on it should
  move backup semantics to their storage provider (bucket replication,
  lifecycle rules, or equivalent) or to external sync tooling. A future
  return of this capability depends on demonstrated demand and upstream
  library support; nothing in the current configuration format promises
  it.
- **`cache` — deprecated.** Filesystem-metadata caching was a v1
  library decorator concept. It has no v3 counterpart in this module;
  operator lifetimes are managed per request instead.
- **`name` and `description` — moved.** Human-readable labels for
  adapters now come from the adapter plugin's own definition, not from
  settings.
- **Driver-specific v1 keys with no v3 equivalent** (for example FTP
  `passive`, `ssl`, `timeout`, `permPrivate`, `permPublic`,
  `transferMode`): each backend plugin now defines its own accepted
  configuration and its own documentation; keys that a plugin does not
  recognize are inert.

Beyond these settings passthroughs, nothing else is imported from 2.x
internals: the module was rebuilt on v3 and the 2.x implementation is
not used as a compatibility base.

## Schemes and core-native coexistence

A scheme with no binding — or one pinned by `disabled => TRUE` — keeps
core's own stream wrapper untouched. Flysystem-backed and core-backed
schemes coexist on the same site by default; replacing a core scheme
requires binding it explicitly.
