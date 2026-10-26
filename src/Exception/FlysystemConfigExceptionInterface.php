<?php

declare(strict_types=1);

namespace Drupal\flysystem\Exception;

/**
 * Marker interface for every typed settings-validation failure.
 *
 * The row's contract requires typed, catchable exceptions per rule; this
 * is the one type consumers catch when they want any settings defect.
 */
interface FlysystemConfigExceptionInterface {

}
