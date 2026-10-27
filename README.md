# Flysystem

Flysystem-backed file storage for Drupal, built on the League/Flysystem v3
library. Storage backends are adapter plugins that bind to file schemes
(`public://`, `private://`, `temporary://`, or any new scheme you define)
through `settings.php`.

## Configuration

Configure a scheme by adding one entry to `$settings['flysystem']`, keyed
by the scheme name, with the adapter's `driver` and its `config`:

```php
$settings['flysystem'] = [
  'public' => [
    'driver' => 'local',
    'config' => [
      'root' => 'sites/default/files',
    ],
  ],
  'private' => [
    'driver' => 'local',
    'config' => [
      // Relative roots anchor against the Drupal install root, never the
      // working directory of the running process.
      'root' => '../private-files',
    ],
  ],
];
```

Optional siblings of `driver`, per scheme entry:

- `publicly_accessible` (bool): whether the scheme serves files over web
  URLs. Defaults to `TRUE` only for `public`. When `TRUE` on a locally
  bound scheme, the root must resolve inside the webroot — publicly
  reachable storage outside the document root cannot be served by URL and
  is rejected with an actionable error naming the resolved path. Remote
  adapters are exempt: they have no webroot concept. (The older
  `config['public']` flag answers the same question and continues to
  work; the explicit sibling wins if both are present.)
- `disabled` (bool): `TRUE` pins the scheme to core's native stream
  wrapper, whatever else binds it.

Scheme names must match `^[A-Za-z0-9-]+$`. PHP cannot register stream
wrappers with underscores, and characters such as dots and pluses are
not understood by Drupal's scheme parser; violations surface as precise
errors when the scheme is used, not at container build.

### Local adapter options

- `root` (required): absolute path, or relative to the Drupal install
  root. The directory is created on the first write, never at
  configuration time.
- `unix_visibility` (optional): file and directory permissions map,
  `['file' => ['public' => 0664, 'private' => 0600], 'dir' => ...]`.
  Defaults come from the site's own `file_chmod_file` and
  `file_chmod_directory` settings, so Drupal's standard permission model
  applies without any per-scheme configuration.
- `link_handling` (optional): `disallow` (default) refuses symlinks when
  traversing listings; `skip` omits symlinked entries.

### Advanced: several schemes on one definition

"Sharing one adapter definition" solves a repetition problem. In the
flat form, every scheme carries its own complete `driver` + `config`
block — correct when each scheme has its own backend, but if two or
three schemes are meant to use the *same* backend configuration, that
block is copy-pasted once per scheme and each copy has to be edited
separately forever after.

The two-layer form writes the configuration once and lists the schemes
that use it: an `adapters` section keyed by adapter id (the definition),
and a `bindings` section keyed by scheme (which scheme uses which
definition):

```php
$settings['flysystem'] = [
  // One definition, written once.
  'adapters' => [
    'uploads' => ['plugin' => 'local', 'config' => ['root' => 'sites/default/files']],
  ],
  'bindings' => [
    // Two schemes deliberately on the same storage: one definition,
    // one place to edit.
    'public' => ['adapter' => 'uploads'],
    'legacy' => ['adapter' => 'uploads'],
    // A binding may also override individual config keys for just its
    // own scheme; here, same plugin and options, different root.
    'exports' => ['adapter' => 'uploads', 'config' => ['root' => 'sites/default/exports']],
  ],
];
```

If every scheme has its own backend configuration, this form adds
nothing — use the flat shape. Both forms are accepted, separately or
combined (a `bindings` entry for a scheme wins over a flat entry of the
same name); every consumer sees one normalized result either way.

## Schemes and core-native coexistence

A scheme with no entry — or one pinned with `disabled => TRUE` — keeps
core's own stream wrapper untouched. Flysystem-backed and core-backed
schemes coexist on the same site; replacing a core scheme requires
binding it explicitly.

## Configuration options that do not carry forward

The 2.x module ran on League/Flysystem v1. Its **user configuration
shape carries forward unchanged** — existing `$settings['flysystem']`
arrays are read as-is. A few 2.x config keys, however, describe features
that did not survive the library conversion. They are accepted and
ignored (never an error), but they do nothing:

- **`replicate` — deprecated.** v1's replication adapter (write each
  file to a second endpoint as a backup) has no League/Flysystem v3
  equivalent and is not reimplemented. Sites relying on it should move
  backup semantics to their storage provider (bucket replication,
  lifecycle rules, or equivalent) or to external sync tooling. A future
  return depends on demonstrated demand and upstream library support;
  nothing in the current format promises it.
- **`cache` — deprecated.** Filesystem-metadata caching was a v1 library
  decorator concept with no v3 counterpart here; operators are managed
  per request instead.
- **`name` and `description` — moved.** Adapter labels now come from the
  plugin's own definition, not from settings.
- **Driver-specific v1 keys** (for example FTP's `passive`, `ssl`,
  `timeout`, `permPrivate`, `permPublic`, `transferMode`): each backend
  plugin now defines its own accepted configuration; keys a plugin does
  not recognize are inert. Consult each adapter plugin's documentation.

Beyond the configuration array itself, nothing is imported from 2.x
internals: this module is a fresh implementation on v3, and the 2.x code
is not used as a base.
