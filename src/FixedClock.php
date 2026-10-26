<?php

declare(strict_types=1);

namespace Orion\Testing;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * A clock that always reads the same instant, so timestamps are part of what a test can assert.
 */
final readonly class FixedClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $now = new DateTimeImmutable('2026-01-01 00:00:00'))
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
