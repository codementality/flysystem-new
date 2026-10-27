# Flysystem

Store your site's files somewhere other than the web server's own disk —
or in a different folder on that disk — without changing anything else
about how Drupal or your modules work. Files keep using ordinary paths
like `public://photo.jpg` and `private://report.pdf`; this module decides
where they actually go.

## Getting started

The module ships with storage on the local disk. Open your site's
`settings.php` file (in `web/sites/default/`) and add a
`$settings['flysystem']` section with one entry per path you want to
control. Each entry is keyed by the path's name and says which storage
to use and where the files live:

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
      'root' => '/../private-files',
    ],
  ],
];
```

That example keeps `public://` where Drupal normally puts it and moves
`private://` to a `private-files` folder sitting next to your web folder
instead of inside it. A path that doesn't start with `/` is measured
from your web folder; `/..` means "one folder up".

## The options you can set

For each path:

- **`driver`** — which storage to use. Out of the box: `local` (a
  folder on the server). Other storage types (Amazon S3 and similar)
  arrive as separate modules you can enable.
- **`config`** — the storage's own settings. For `local`, that means
  `root`: the folder the files live in.
- **`publicly_accessible`** — whether files in this path can be opened
  directly through a web address. Only `public` assumes `TRUE`; every
  other path assumes `FALSE`. A publicly reachable folder must live
  inside your web folder — if it doesn't, the module says so plainly
  when the path is used, because files outside the web folder cannot be
  served by URL. (Storage that lives on another service, like S3, is
  served by its own web address and is exempt from this rule.)
- **`disabled`** — set to `TRUE` to hand this path back to Drupal's
  normal storage, even if something else configured it.

Rules for the path name itself: use letters, numbers, and hyphens
(`my-files` is fine; `My_files`, spaces, dots, and colons are not).

## File permissions

You never configure these. Files and folders are created with your
site's standard Drupal permissions — the same ones set by the
`file_chmod_file` and `file_chmod_directory` settings you may already
have — so private folders land as private (owner read/write only)
without any extra work.

## Upgrading from the old (2.x) version of this module

Your existing `$settings['flysystem']` entries keep working as written.
A few older settings keys belong to features that no longer exist and
now do nothing — the module accepts them quietly so nothing breaks, but
plan to remove them:

- **`replicate`** — older versions could write every file to a second
  destination as a backup. That feature has no replacement here; use
  your storage provider's own replication (S3 bucket rules and the like)
  or a server-level sync tool instead.
- **`cache`** — old versions could cache file metadata; this one
  doesn't need to.
- **`name` and `description`** — labels shown in the interface now come
  from the storage module itself, not from settings.
- **Old FTP-style keys** such as `passive`, `ssl`, `timeout`,
  `permPrivate`, `permPublic`, `transferMode` — each storage module now
  documents its own settings; keys a module doesn't know are ignored.

Everything else from 2.x — the settings layout, how paths are written —
is unchanged on purpose. The old code itself is not used: this is a
fresh implementation built on the current storage library.

## Paths you don't configure

A path with no entry here behaves exactly as stock Drupal does. Flysystem
and normal storage can sit side by side on the same site; you choose, per
path, which one it uses.

## Writing a module that adds new storage?

If you're a developer adding support for another storage service (or
contributing one to this project), read
[DEVELOPER_NOTES.md](DEVELOPER_NOTES.md) — it covers the plugin
interface, discovery, and the settings that reach your code.
