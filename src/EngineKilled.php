<?php

declare(strict_types=1);

namespace Orion\Testing;

use RuntimeException;

/**
 * What {@see FaultyEngine} throws where the engine dies: a connection lost mid-call, as far as Orion can tell.
 */
final class EngineKilled extends RuntimeException
{
}
