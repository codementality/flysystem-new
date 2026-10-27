<?php

declare(strict_types=1);

namespace Drupal\flysystem\Exception;

/**
 * Thrown when a bound adapter plugin reports it cannot work right now.
 *
 * Typically the plugin's required third-party package is not installed.
 * The message names the plugin; which package it needs is the plugin's
 * own knowledge (it declares availability), never a registry kept here.
 */
class FlysystemPackageMissingException extends \RuntimeException {

  /**
   * Constructs the exception for an unavailable adapter plugin.
   *
   * @param string $pluginId
   *   The adapter plugin id whose availability check failed.
   * @param string $reason
   *   Optional detail supplied by the failure context.
   */
  public function __construct(string $pluginId, string $reason = '') {
    $message = sprintf("The adapter plugin '%s' is not available: its required package is not installed%s.", $pluginId, $reason === '' ? '' : " ({$reason})");
    parent::__construct($message);
  }

}
