<?php

declare(strict_types=1);

namespace Orion\Testing\Equivalence;

use InvalidArgumentException;

use function array_values;
use function count;
use function sprintf;

/**
 * A deterministic generator: the same seed gives the same sequence on every machine and PHP version, so a failing
 * property can be replayed from the seed it reports. A 31-bit linear congruential generator is enough for picking.
 */
final class Seeded
{
    private const int MULTIPLIER = 1103515245;
    private const int INCREMENT = 12345;
    private const int MASK = 0x7FFFFFFF;

    private int $state;

    public function __construct(public readonly int $seed)
    {
        $this->state = $seed & self::MASK;
    }

    /**
     * An integer in [$min, $max].
     */
    public function int(int $min, int $max): int
    {
        if ($max < $min) {
            throw new InvalidArgumentException(sprintf('An empty range: %d..%d.', $min, $max));
        }
        $this->state = ($this->state * self::MULTIPLIER + self::INCREMENT) & self::MASK;

        return $min + (($this->state >> 8) % ($max - $min + 1));
    }

    /**
     * A float in [0, 1) with three decimals, so values print and compare plainly.
     */
    public function float(): float
    {
        return $this->int(0, 999) / 1000;
    }

    public function chance(int $percent): bool
    {
        return $this->int(1, 100) <= $percent;
    }

    /**
     * @template T
     * @param list<T> $items
     * @return T
     */
    public function pick(array $items): mixed
    {
        return $items[$this->int(0, count($items) - 1)];
    }

    /**
     * @template T
     * @param list<T> $items
     * @return list<T> `$count` distinct items, in the order drawn
     */
    public function some(array $items, int $count): array
    {
        $drawn = [];
        $remaining = $items;
        for ($i = 0; $i < $count && $remaining !== []; ++$i) {
            $index = $this->int(0, count($remaining) - 1);
            $drawn[] = $remaining[$index];
            unset($remaining[$index]);
            $remaining = array_values($remaining);
        }

        return $drawn;
    }
}
