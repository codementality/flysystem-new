<?php

declare(strict_types=1);

namespace Drupal\flysystem\Adapter;

/**
 * Immutable value object implementing the capability contract.
 *
 * Only `registrationMode` is required; every other field defaults to the
 * most conservative answer. That is the backward-compatibility mechanism
 * for B6: future capability fields are added as new defaulted constructor
 * parameters and new getters on the interface, and no existing construction
 * site or consumer breaks.
 */
final class AdapterCapabilities implements AdapterCapabilitiesInterface {

  /**
   * Constructs the capability profile.
   *
   * @param \Drupal\flysystem\Adapter\RegistrationMode $registrationMode
   *   How the backend registers with PHP stream machinery.
   * @param bool $supportsDirectories
   *   Whether directories are meaningfully supported.
   * @param bool $supportsVisibility
   *   Whether visibility (permissions) are honored.
   * @param \Drupal\flysystem\Adapter\UrlMode $urlMode
   *   Which URL generation the backend provides.
   * @param bool $streamsNatively
   *   Whether stream operations are native rather than whole-file buffers.
   */
  public function __construct(
    private readonly RegistrationMode $registrationMode,
    private readonly bool $supportsDirectories = FALSE,
    private readonly bool $supportsVisibility = FALSE,
    private readonly UrlMode $urlMode = UrlMode::None,
    private readonly bool $streamsNatively = FALSE,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function registrationMode(): RegistrationMode {
    return $this->registrationMode;
  }

  /**
   * {@inheritdoc}
   */
  public function supportsDirectories(): bool {
    return $this->supportsDirectories;
  }

  /**
   * {@inheritdoc}
   */
  public function supportsVisibility(): bool {
    return $this->supportsVisibility;
  }

  /**
   * {@inheritdoc}
   */
  public function urlMode(): UrlMode {
    return $this->urlMode;
  }

  /**
   * {@inheritdoc}
   */
  public function streamsNatively(): bool {
    return $this->streamsNatively;
  }

}
