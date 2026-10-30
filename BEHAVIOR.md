# How this module behaves when a storage scheme is bound

This document tells you, in plain words, what changes — and what
deliberately does not change — when you point a core file scheme
(`public://`, `private://`, or `temporary://`) at Flysystem storage on a
local folder. It is written for site builders and administrators: every
answer below is checked automatically against the test suite named with
it, so what you read here is what the software does.

Anything not listed as different behaves exactly likeDrupal core's own
storage.

## Reading and writing files

Opening, reading, writing, seeking, appending, truncating, copying,
moving and deleting files behave exactly as they do on core's local
wrappers. One detail of the implementation is invisible to callers: a
write handle collects the file in memory and hands the whole file back
to storage when it is closed or flushed, so stream functions report the
same success and failure answers as a real file handle.

Proven by: `WrapperHandleSemanticsTest`, `WrapperHandleEndToEndTest`.

## Locks are different

Advisory file locking (`flock()`) is **refused** on a bound scheme: the
call reports failure instead of taking a lock. Core's local wrapper can
lock a real file because its handle stays open on disk; this module's
handle is a memory buffer with no disk file behind it for the life of
the stream, and reaching past the storage layer for one would bypass
the path protections the binding exists to provide. Code that relies on
`flock()` for coordination on a bound scheme needs its locking elsewhere
(for example, at the database or cache layer).

Proven by: `WrapperHandleEndToEndTest`, `WrapperHandleSemanticsTest`.

## File metadata

Existence, size, modification time, the file-or-directory type, and the
permission bits all answer from the storage itself — never from a
direct look at a path. Answers match core's for the same file. Fields
that the storage layer does not model (access time, change time, owner
and group identity) are filled with documented stand-ins: times copy
the modification time, ownership reports the running process. No field
is ever missing or null where PHP expects a number.

Proven by: `WrapperMetadataOperationsTest`, `WrapperMetadataEndToEndTest`.

## Touching files is close, with one honest difference

`touch()` works: it updates the file's modification time and creates an
empty file when the path does not exist yet. The difference: a timestamp
passed explicitly cannot be applied — the storage abstraction has no
"set the clock" call — so the file's modification time lands on the
moment of the write, not the requested time. Code that touches a file
to mark it recent is fine; code that pretends history will not be
honest with a bound scheme.

Proven by: `WrapperMetadataEndToEndTest`, `WrapperMetadataOperationsTest`.

## Permissions work through the binding's own model

`chmod()` succeeds, but answers through the permission vocabulary the
storage actually speaks — public or private — rather than arbitrary
mode bits: a mode that grants read access beyond the owner asks the
storage for public, an owner-only mode for private. The exact bits on
disk then follow the binding's configured mode map, which is the same
map every normal file write already applies. In other words:
permissions are the binding's policy, not the caller's number, and both
permissions and file contents stay coherent.

Proven by: `WrapperMetadataEndToEndTest`, `WrapperMetadataOperationsTest`.

## Ownership changes are refused

`chown()` (and group ownership) has no answer in a storage abstraction
that does not know about users, so it reports failure — cleanly, with a
log record where the failure matters, never a crash.

Proven by: `WrapperMetadataOperationsTest`, `WrapperMetadataEndToEndTest`.

## Directories and renames

Recursive `mkdir()`, directory listing (including the `.` and `..`
entries PHP's own listing functions expect), refusing to remove a
non-empty directory, deleting, and renaming all behave as core's local
storage does. A rename inside the same bound storage is a server-side
move: no file content travels through PHP.

Proven by: `WrapperMetadataEndToEndTest`, `WrapperMetadataOperationsTest`.

## Real paths are lexical, and answer even for missing files

`realpath()` on a bound local scheme returns the folder the binding
points at joined to the path inside it, cleaned of `.` and `..`. Two
differences from core follow from doing it that way: symbolic links are
never resolved (the answer is the configured location, not whatever a
link points to), and a path that does not exist yet still answers with
where it would live, where core reports "no path". Existence checks
should ask about existence directly, not read meaning into a real-path
answer.

Proven by: `WrapperMetadataEndToEndTest`, `ManagedFileMechanicsTest`.

## Paths that try to escape the bound folder are refused

A path that walks out of the bound root — a leading `../` on a public
scheme, for instance — fails the operation instead of reaching above the
storage root. Core's own local wrapper refuses raw escapes as well, so
the strengthening is about *where* the boundary is enforced and with what
guarantees: through the binding the refusal comes from the storage
library's traversal guard, applied to every operation and independent of
whether a real path happens to resolve, and a failed attempt is always a
refused operation with a log record rather than silent success or a
thrown error. Directory separators inside names follow the
storage library's rules, so a backslash in a name is part of the name
on every platform, where core's local wrapper's conversion would split
it on Windows. Names containing control characters are refused outright.
The traversal guard's refusals are ordinary failed operations with log
records, never exceptions escaping into a page.

Proven by: `TraversalMatrixTest`, `PathNormalizationContractTest`,
`GuardRefusalSoftFailureTest`.

## Broken configuration fails files, not pages

When a binding is misconfigured in a way that only the storage layer
can discover, the first file operation touching that scheme fails
cleanly: the operation reports failure the way a filesystem problem
would, one actionable record goes to the module's log channel naming
the scheme and the defect, and the page request continues serving
everything else. The same problem appears in the status report and in
the verification command output, worded the same way, because all three
read one source of truth. No configuration error can crash a request
by escaping a file call.

Proven by: `WrapperBindingErrorsTest`, `BrokenBindingGracefulFailureTest`,
`BrokenBindingMetadataRefusalTest`.

## Uploads, managed files, image styles

A form upload stages on `temporary://` and lands on the destination
scheme exactly as core's managed-file flow does; the saved file entity
reports the size read back through the storage, and stored paths stay
scheme-relative — binding a scheme changes where bytes live, never what
the database says about them. Deleting a file entity removes the object
from storage; if removal fails (a full or read-only backend), the
entity still deletes and the failure is recorded, which is core's own
posture. Image style derivatives generate into the bound folder with
their effects applied, and the webserver serves them at the same URL
core used — the URL of a public file is a fact about the site, not
about which folder currently stores it.

Proven by: `ManagedFileMechanicsTest`, `ManagedFileUploadTest`,
`WrapperUrlAndUiSurfaceTest`.

## Bindings mix freely, and rebinding relocates meaning, not data

Every combination of bound and unbound schemes works side by side: each
scheme answers from its own storage, a bound scheme's folder never
receives another scheme's files, and an unbound scheme keeps core's
class, location and behavior untouched. Switching a scheme to a new
root makes files written under the old root invisible — their bytes
remain exactly where they were written — while new writes follow the
new root; that is why re-pointing a binding between deployments is a
planned move with a planned content relocation, not a rename.

Proven by: `MixedBindingCoexistenceTest`,
`WrapperRegistrationCoexistenceTest`.

## Names and listings

Scheme names, descriptions, and the places core lists writable schemes
(field widgets, upload locations, image style settings) read the same
labels core's own wrappers provide, so a bound scheme appears in the
administrative user interface where the old one did.

Proven by: `WrapperUrlAndUiSurfaceTest`,
`WrapperRegistrationCoexistenceTest`.
