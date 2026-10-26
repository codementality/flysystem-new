<?php

declare(strict_types=1);

namespace Drupal\flysystem\Adapter;

/**
 * The capability contract an adapter plugin returns about itself.
 *
 * Field set is deliberately minimal (open decision B6): five parameters,
 * each answering a question a factory or wrapper consumer in M1/M2 actually
 * asks. Later fields must arrive additively — consumers depend on this
 * interface only, and new getters can be added alongside defaults on the
 * value object without breaking any existing construction or consumption.
 */
interface AdapterCapabilitiesInterface {

  /**
   * How the scheme would register with PHP stream machinery.
   */
  public function registrationMode(): RegistrationMode;

  /**
   * Whether the backend meaningfully supports directories (create/list).
   */
  public function supportsDirectories(): bool;

  /**
   * Whether the backend honors visibility (the API's permission model).
   */
  public function supportsVisibility(): bool;

  /**
   * Which URL generation the backend provides, if any (§1.11).
   */
  public function urlMode(): UrlMode;

  /**
   * Whether stream operations are served natively or buffered whole-file.
   */
  public function streamsNatively(): bool;

}
