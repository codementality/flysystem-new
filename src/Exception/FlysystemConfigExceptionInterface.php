<?php

declare(strict_types=1);

namespace Drupal\flysystem\Exception;

/**
 * Marker interface for every typed settings-validation failure.
 *
 * Validation failures are typed and catchable per rule; this is the one
 * type consumers catch when they want any settings defect at once.
 */
interface FlysystemConfigExceptionInterface {

}
