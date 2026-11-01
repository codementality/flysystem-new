# Developer notes

For developers writing modules that add storage backends (adapters) to
this module, or extending the module itself. The user-facing
configuration guide is in README.md; AI-assisted contributors should
read AGENT_AI_DEVELOPMENT.md at the module root first.

## The plugin system

Adapters are standard Drupal plugins, discovered exactly the way Search
API discovers its datasources and backends. A backend lives in your
module at `src/Plugin/FlysystemAdapter/`, carries the
`#[FlysystemAdapter]` attribute (`Drupal\flysystem\Attribute\FlysystemAdapter`),
and implements `Drupal\flysystem\Adapter\FlysystemAdapterInterface` —
normally by extending `FlysystemAdapterBase`:

```php
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\flysystem\Adapter\FlysystemAdapterBase;
use Drupal\flysystem\Attribute\FlysystemAdapter;
use League\Flysystem\Dropbox\DropboxAdapter;
use League\Flysystem\FilesystemAdapter;

#[FlysystemAdapter(id: 'dropbox', label: new TranslatableMarkup('Dropbox'))]
final class Dropbox extends FlysystemAdapterBase {

  public function adapter(): FilesystemAdapter {
    // Return the configured League adapter OBJECT — the raw backend,
    // not a Filesystem operator. Your binding's merged configuration
    // is in $this->configuration. Build the object once and return the
    // same one on every call; the base module's factory wraps it in a
    // Filesystem operator with the shared operation defaults.
    return $this->adapter ??= new DropboxAdapter(...);
  }

  public function isAvailable(): bool {
    // Report whether your backend can work right now — typically: is
    // the third-party package your adapter needs installed? The
    // factory checks this BEFORE calling adapter() and fails loudly,
    // naming your plugin, when it returns FALSE.
  }

}
```

That class is the entire on-ramp: no service definitions, no tags, no
registry edits. `FlysystemAdapterBase` carries the standard plugin
constructor (configuration, id, definition), and the `id` is what
sites write as `driver` in their settings — lowercase letters, digits,
and underscores, following the upstream library's adapter slugs.

Adapters needing container services implement
`ContainerFactoryPluginInterface` like core plugins do; the shipped
local adapter does this to receive the app root.

## Ids are overridable names, not unique constraints

If two modules declare the same adapter id, the later one discovered
wins — and that is the design, not an accident. It lets a site's own
module re-decide what a driver name means (a corrected implementation,
an environment-specific one) without touching any binding that
references it. Do not build anything that assumes your id is claimed
exclusively, and do not add duplicate-id validation to the base module.

## How configuration reaches your plugin

Sites configure storage in `settings.php` as one entry per scheme, each
with a `driver` and a `config` array. Internally every configuration
source (settings now; config entities later) presents the same shape,
and `BindingResolver` produces, per scheme, one merged definition:

- your adapter's `config`, deep-merged with any binding-level overrides
  (higher-precedence source wins per leaf);
- plus the reserved key `allow_relative_path_traversal` (see below).

The merged array arrives as your plugin's constructor configuration
(`$this->configuration`). Which keys mean what is your adapter's
business; unrecognized keys must be ignored, not rejected. Document
your accepted keys in your own module's README.

### The reserved traversal-posture key

The factory decides path-traversal strictness from the adapter's
declared feature (see below) and passes it in the merged configuration
as `allow_relative_path_traversal` — the League/Flysystem library's own
config key. The factory composes it into the operator's option defaults
for you; adapters normally just see it in configuration and do not act
on it. `TRUE` (local storage): interior `..` segments resolve within
the configured root. `FALSE` (remote-style storage): they are rejected
outright. Leading root escapes throw either way — that guarantee is
provided by the library and must not be worked around.

### Declaring local storage

Adapters whose data lives on the Drupal server's own filesystem
implement the marker interface `Drupal\flysystem\Adapter\LocalStorageInterface`
(no methods). That declaration is how webroot-containment validation
("a publicly reachable folder must live inside the web folder") and the
traversal posture decide your storage is local. Remote-style backends
implement nothing extra and are treated accordingly. Never expect the
base module to branch on your plugin's name — it does not, and any
future capability question follows the same marker-interface pattern.

## Internal machinery you do not need

`DefinitionChecksumTrait` computes operator identity keys for the base
module's own factory (memoization) and verification tooling. Adapter
modules never implement, use, or depend on it. The same applies to the
definition-source interfaces: those describe where configuration comes
from inside this module; adapters consume only the merged array handed
to their constructor.

## Compatibility promises

Adding methods to `FlysystemAdapterInterface` is avoided: the interface
grows only through separate small marker interfaces that adapters may
implement (like `LocalStorageInterface`), which older adapters simply
do not. Anything the base module may call on your plugin is declared
in the interface you implement — duck-typing on undeclared methods is
not used.

## Testing expectations

Adapters should ship tests proving: `isAvailable()` reports the truth
about the backend's package; `adapter()` returns a working object —
ideally proven through the real pipeline: a scheme bound to your
driver in settings, a kernel test over the booted container, asserting
bytes round-trip through the operator the factory builds (in-memory or
sandbox service, never production infrastructure); unknown config keys
are ignored; and — where relevant — roots anchor to the app root and
the traversal posture is honored. Keep your tests runnable in isolation
from this module's own fixtures.

## Documented deviations

Where the library cannot express something the surrounding integration
requires, the base module implements what it can express, proves the
constraint with a named test, and records the deviation here — one
entry per proving test, in plain language, never a silent gap.
