<?php

declare(strict_types=1);

namespace Orion\Testing;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * A clock a test moves by hand, for leases and grace windows.
 */
final class SettableClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $now = new DateTimeImmutable('2026-01-01 00:00:00+00:00'))
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}
