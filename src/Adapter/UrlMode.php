<?php

declare(strict_types=1);

namespace Drupal\flysystem\Adapter;

/**
 * Which optional URL generators the adapter's backend actually provides.
 *
 * URL generation is not part of the required FilesystemAdapter contract —
 * `publicUrl`/`temporaryUrl` come from the `public_url` config key or an
 * adapter implementing the optional `League\Flysystem\UrlGeneration\*`
 * interfaces (evaluation §1.11). This enum records what a backend honestly
 * supports so external URL behavior is a per-binding decision, never an
 * assumption from the library.
 */
enum UrlMode {

  // No URL generation from the adapter; Drupal must supply it or omit it.
  case None;

  // The backend can produce public URLs only.
  case Public;

  // The backend can produce temporary (signed) URLs only.
  case Temporary;

  // The backend can produce both public and temporary URLs.
  case PublicAndTemporary;

}
