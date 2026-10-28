# Developer notes

For developers writing modules that add storage backends (adapters) to
this module, or extending the module itself. The user-facing
configuration guide is in README.md.

## The plugin system

Adapters are standard Drupal plugins. A backend lives in your module at
`src/Plugin/FlysystemAdapter/`, annotated with the `#[FlysystemAdapter]`
attribute (`Drupal\flysystem\Attribute\FlysystemAdapter`) and
implementing `Drupal\flysystem\Plugin\FlysystemAdapterInterface`:

```php
use Drupal\flysystem\Attribute\FlysystemAdapter;
use Drupal\flysystem\Plugin\FlysystemAdapterBase;
use Drupal\flysystem\Plugin\FlysystemAdapterInterface;
use League\Flysystem\FilesystemOperator;

#[FlysystemAdapter(id: 'mybackend', label: new TranslatableMarkup('My backend'))]
class MyBackend extends FlysystemAdapterBase implements
FlysystemAdapterInterface {

  public function buildFilesystem(array $config = []): FilesystemOperator {
    // Return a configured League\Flysystem\FilesystemOperator for the
    // settings in $config. Build it here; do not hold state between calls.
  }

  public function isAvailable(): bool {
    // Report whether your backend can work right now — typically: is the
    // third-party library your adapter needs installed? The factory checks
    // this BEFORE calling buildFilesystem() and fails loudly, naming your
    // plugin, when it returns FALSE.
  }

}
```

`FlysystemAdapterBase` supplies the standard configuration plumbing from
the core plugin system (`getConfiguration()` / `setConfiguration()`).
The `id` is what sites write as `driver` in their settings; constrain it
to lowercase letters, digits, and underscores.

Discoverability follows normal Drupal rules: your module's plugin is
available exactly while your module is enabled. The base module keeps no
registry of backends and never branches on plugin names.

Adapters needing container services (configuration overrides, request
context) implement `ContainerFactoryPluginInterface` like core plugins
do; the local adapter does this to receive the app root.

## How configuration reaches your plugin

Sites configure storage in `settings.php` as one entry per scheme, each
with a `driver` and a `config` array. Internally every configuration
source (settings now; config entities later) presents the same two plain
arrays — adapter definitions and scheme bindings — and
`BindingResolver` produces, per scheme, one merged definition:

- your adapter's `config`, deep-merged with any binding-level overrides
  (higher-precedence source wins per leaf);
- plus the reserved key `allow_relative_path_traversal` (see below).

Your `buildFilesystem($config)` receives that merged array in full. Which
keys mean what is your adapter's business; unrecognized keys should be
ignored, not rejected. Document your accepted keys in your own module's
README.

### The reserved traversal-posture key

The factory decides path-traversal strictness from the adapter's declared
feature (see below) and passes it in the merged configuration as
`allow_relative_path_traversal` (boolean — the League/Flysystem library's
own config key). Forward it to the `Filesystem` you construct, as the
local adapter does; do not reinterpret it. `TRUE` (local storage):
interior `..` segments resolve within the configured root. `FALSE`
(remote-style storage): they are rejected outright. Leading root escapes
throw either way — that guarantee is provided by the library and must not
be worked around.

### Declaring local storage

Adapters whose data lives on the Drupal server's own filesystem
implement the marker interface `Drupal\flysystem\Plugin\LocalStorageInterface`
(no methods). That declaration is how webroot-containment validation
("a publicly reachable folder must live inside the web folder") and the
traversal posture decide your storage is local. Remote-style backends
implement nothing extra and are treated accordingly.

## Internal machinery you do not need

`DefinitionChecksumTrait` computes operator identity keys for the base
module's own factory (memoization) and verification tooling. Adapter
modules never implement, use, or depend on it. The same applies to the
definition-source interfaces: those describe where configuration comes
from inside this module; adapters consume only the merged array handed to
`buildFilesystem()`.

## Compatibility promises

Adding methods to `FlysystemAdapterInterface` is avoided: the interface
grows only through separate small feature interfaces that adapters may
implement (like `LocalStorageInterface`), which older adapters simply do
not. Anything the base module may call on your plugin is declared in the
interface you implement — duck-typing on undeclared methods is not used.

## Testing expectations

Adapters should ship tests proving: `isAvailable()` reports the truth
about the backend's package, `buildFilesystem()` returns a working
operator against a real or in-memory service, unknown config keys are
ignored, and — where relevant — the traversal posture key is forwarded
and honored. Group your tests with `@group yourmodule` and keep them
runnable in isolation from this module's own fixtures.
