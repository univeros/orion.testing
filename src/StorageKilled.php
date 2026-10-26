<?php

declare(strict_types=1);

namespace Orion\Testing;

use RuntimeException;

/**
 * What {@see FaultyStorage} throws where the database dies.
 */
final class StorageKilled extends RuntimeException
{
}
