# For AI development agents — read this before writing a patch

You are working on the **Flysystem** Drupal module with no context
from its originating project. This document gives you the
architecture, the invariants the test suite enforces, and the
conventions an aligned patch follows. Where a rule matters, it names
the test that proves it — read the test when in doubt; **tests are
the final authority over any prose, including this file.** If this
document and the code disagree, the code and tests win, and the
document is a bug.

## What this module is

A storage-backend layer for Drupal's stream-wrapper schemes. A site
binds scheme names (`public`, `private`, or any custom scheme) in
`settings.php` to a named **driver** plus a configuration array. At
runtime a resolved binding produces a configured
`League\Flysystem\FilesystemOperator` (Flysystem 3.x) — files keep
using ordinary `public://path` URIs; this module decides where bytes
actually go.

Storage backends are **Drupal plugins**: a contrib module ships one
attributed class and its adapter exists exactly while that module is
enabled. Nothing here keeps a registry of backends or branches on
driver names.

**Current scope of this codebase.** The foundation is complete:
adapter plugin system, binding resolution, settings validation, the
operator factory, verification tooling, and a status report. The
stream wrapper classes that intercept `public://` file operations at
the PHP level, the remote-driver roster (S3, SFTP, …),
config-entity-driven bindings, and admin UI are **later work built on
this foundation — do not assume they exist.** Read `src/` to see what
ships today.

## Architecture map

```
flysystem.services.yml       All wiring; autowire/ctor injection.
src/Attribute/               The #[FlysystemAdapter] attribute.
src/Adapter/                 Contract: interface, base, manager.
src/Plugin/FlysystemAdapter/ Shipped local adapter plugin.
src/Definition/              Config sources (settings.php today).
src/Resolution/              BindingResolver: merged definitions.
src/Validation/              SettingsValidator: typed, local errors.
src/Factory/                 OperatorFactory: the operator seam.
src/Verification/            Inspector, verifier, status report.
src/Exception/               Typed errors naming scheme/driver.
src/Util/                    LexicalPath: no realpath, no disk.
modules/flysystem_memory/    Hidden test-only adapter module.
tests/src/Unit/              Pure-logic tests.
tests/src/Kernel/            Real-container discovery/wiring tests.
tests/modules/               Hidden fixture modules (contrib proof).
docs/                        Long-form documentation.
README.md                    User-facing config guide.
```

## The pipeline, one call deep

`operatorFor($scheme)` on `flysystem.operator_factory`:

1. `BindingResolver::resolve()` merges the scheme's binding with its
   adapter definition from the highest-precedence source that
   mentions it (sources are ordered; per-leaf deep merge). Unbound
   or `disabled` schemes resolve to `NULL` — core-native storage
   stays untouched.
2. Validation is deferred to the touched scheme only: one bad
   sibling entry never breaks a working scheme.
3. The resolved definition's `driver` names an adapter **plugin
   id**. `FlysystemAdapterManager::createInstance($id, $config)` —
   the plugin system builds the instance; module code never calls
   `new` on an adapter class.
4. The plugin's `isAvailable()` is checked before anything is
   built; `FALSE` raises a typed, logged package-missing error
   naming the driver.
5. `$plugin->adapter()` returns the raw League adapter object; the
   factory alone wraps it in `new Filesystem($adapter, $options)`
   with the operation defaults (visibility, traversal posture)
   composed from the merged configuration.
6. The operator is memoized for the request under an identity
   checksum of (plugin id, merged config).

## Invariants — breaking any of these turns the suite red

1. **Adapters are plugins.** A new backend is one class in your
   module's `src/Plugin/FlysystemAdapter/` with
   `#[FlysystemAdapter(id: ..., label: ...)]`, extending
   `FlysystemAdapterBase` (or implementing the interface directly).
   No YAML registration. Proof lives in
   `tests/src/Kernel/Adapter/ContribStyleAdapterDiscoveryTest.php`
   and its fixture module
   `tests/modules/flysystem_adapter_test/` — copy that pattern.
2. **Never `new` an adapter class in module code.** Construction
   goes through the manager so definitions, dependencies, caching,
   and alteration behave like every other Drupal plugin system.
3. **Plugin ids are overridable names, not unique constraints.** If
   two modules declare the same id, the later one discovered wins —
   this is *deliberate*: it is the layering channel that lets a
   site's own module re-decide what a driver name means (per
   deployment, per environment) without touching any binding that
   references it. Never add collision detection or
   duplicate-driver validation. Data-plane validation stays loud;
   code-plane override is the feature.
4. **Driver/id grammar is `^[a-z0-9_]+$`.** Names follow Flysystem's
   official-adapter slugs, converted to underscores (hyphens are not
   legal). A hyphenated driver spelling in settings is rejected with
   a typed error naming the canonical underscore form
   (`tests/src/Unit/Validation/SettingsValidatorContractTest.php`).
5. **Features are declared by marker interfaces, never by name
   lists.** `LocalStorageInterface` (no methods) is how an adapter
   announces "my storage is on this server's disk" —
   webroot-containment validation and the traversal posture both
   read that declaration
   (`src/Verification/AdapterFeatureInspector.php`). The interface
   never grows methods; new capabilities arrive as new marker
   interfaces that older adapters simply do not implement.
6. **One assembly path.** Only `OperatorFactory` composes the
   `Filesystem` operator and its defaults. `adapter()` hands out the
   raw League adapter object; never return or store a raw adapter
   for consumers to wrap themselves — traversal protection lives at
   the operator layer.
7. **`isAvailable()` is the truth** about optional packages
   (`class_exists()` on what you actually need). Unavailable is a
   typed, logged error at use time — and a structured *finding*,
   never a throw, through `BindingVerifier`.
8. **Lazy until touched.** Nothing validates, builds, or hits disk
   until a scheme is actually used; constructing an adapter object
   or an operator creates nothing on disk (roots are created on
   first write; local storage disables the library's eager root
   creation).
9. **No path magic.** Relative roots anchor to the app root, never
   the process CWD; `..` in *configured roots* collapses lexically
   (`LexicalPath`); `..` attempting to *escape* a root throws
   through the operator. `realpath()` is never used (it breaks
   not-yet-created roots and chases symlinks the OS owns).
10. **Test-only backends stay in hidden modules.** The in-memory
    adapter ships in `modules/flysystem_memory/`, a module sites
    never enable and UIs never list. Adapter presence is module
    presence.
11. **The base module registers zero `stream_wrapper` services** —
    integration arrives through its own documented seam, enforced
    by `tests/src/Unit/FlysystemScaffoldContractTest.php`.

## Settings format (user-facing)

Canonical text is `README.md`. Shape, for orientation:

```php
$settings['flysystem'] = [
  'public' => [
    'driver' => 'local',
    'config' => ['root' => 'files'],
  ],
  'private' => [
    'driver' => 'local',
    'config' => ['root' => '../private', 'public' => FALSE],
  ],
  'assets' => [
    'driver' => 'local',
    'config' => ['root' => 'assets'],
    'disabled' => TRUE,
  ],
];
```

One entry per scheme; `driver` is the plugin id; `config` is opaque
to this module (your adapter owns its keys and must ignore unknown
ones); `disabled` ignores the entry silently; `config['public']` is
the canonical public-files flag. Legacy 2.x spellings inside
`config` are accepted as aliases — see
`tests/src/Unit/Definition/SettingsSourcePublicFlagParityTest.php`
and `X2FormatReplayTest.php`.

## Test conventions

- PHPUnit 11 attributes throughout: `#[Group('flysystem')]` and
  `#[CoversNothing]` on every test class.
- Kernel tests: `#[RunTestsInSeparateProcesses]`; bindings are
  injected with `$this->setSetting('flysystem', [...])` in
  `setUp()` — KernelTestBase has **no** `$settings` property;
  modules go in `static::$modules`.
- Choose the level by the question: pure logic → Unit; anything
  about discovery, the container, caching, hooks, or real wiring →
  Kernel. Unit tests may double the manager
  (`createMock`) — the kernel tests are where the real plugin
  system is proven.
- Fixture modules a third party would mirror live under
  `tests/modules/`, hidden, enabled only by the tests that need
  them.
- Where this module implements behavior Drupal core also defines
  (paths, uploads, file semantics), the authoritative spec is
  **core's own kernel test corpus** — new wrapper work extends the
  copied, scheme-parameterized corpus, and never invents a parallel
  private contract. Green tests written against your own guesses
  prove nothing.
- A merged class must satisfy its documented behavior fully. Do
  not merge methods with placeholder returns justified as
  "finished later by another ticket".

## Gates

Run all three; each must exit 0:

```
.ddev/commands/web/phpunit
.ddev/commands/web/phpcs
.ddev/commands/web/phpstan
```

The phpunit wrapper runs the full suite. For a targeted run:

```
vendor/bin/phpunit -c web/core/phpunit.xml.dist \
  --bootstrap web/core/tests/bootstrap.php <test paths>
```

If your environment lacks these wrappers, call the tools directly
from the project root (core's `phpunit.xml.dist`, Drupal coding
standards for phpcs) and treat exit codes as the gate.

## Comment and documentation hygiene (enforced)

Shipped text — code comments, YAML, `README.md`, `docs/*.md`, and
this file — must stand alone: no issue or PR numbers, no milestone
or decision codes, no references to private workspaces or process
history. Cite public sources only (Drupal core paths, upstream
library docs). `CommentsAreSelfContainedTest` scans these files and
fails the build on violations.

## Deviations from the library

Where Flysystem cannot express something Drupal file semantics
require: implement what it *can* express, prove the constraint with
a test, and record the deviation in `docs/DEVELOPER_NOTES.md`, one
entry per proving test. Silent half-behaviors are worse than
documented gaps.

## Patch recipes

- **New storage backend:** one attributed plugin class in
  `src/Plugin/FlysystemAdapter/`; read config from
  `$this->configuration` (arrives in the constructor via
  `createInstance`); implement `adapter()` (build the League object
  once, return it on every call) and, if it needs an optional
  package, an honest `isAvailable()`; if your storage is on the web
  server's own disk, implement `LocalStorageInterface` and anchor
  relative roots with `LexicalPath::resolve()` against the app root
  (the shipped local plugin is the full worked pattern). Ship
  tests: availability truth; a working operator through the factory
  for a scheme bound to your driver; unknown config keys ignored;
  kernel discovery proof if your module is meant for others to
  enable.
- **New validation rule:** `SettingsValidator` + a typed exception
  in `src/Exception/` + a unit test per branch; errors must name
  the scheme and the offending value, stay local to the touched
  scheme, and surface through `BindingVerifier` as findings without
  throwing.
- **New service:** declare it in `flysystem.services.yml` with
  constructor injection or autowire; avoid `\Drupal::` static calls
  in service classes. The one sanctioned exception is the
  pre-container `Settings::get()` mode read in the local plugin,
  matching core's own practice — document any new exception and
  route it through a test.
- **Docs:** update `README.md` for user-visible format changes and
  `docs/DEVELOPER_NOTES.md` for adapter-author-facing contract
  changes; the hygiene rules above apply to what you write.

## Compatibility promises

`FlysystemAdapterInterface` never gains methods; capabilities grow
through marker interfaces only. Anything the base module calls on an
adapter plugin is declared on the interface it implements — no
duck-typed calls on undeclared methods.
