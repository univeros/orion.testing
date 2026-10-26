<?php

declare(strict_types=1);

namespace Orion\Testing;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use Orion\Storage\Column;
use Orion\Storage\Condition;
use Orion\Storage\DuplicateKey;
use Orion\Storage\Index;
use Orion\Storage\Schema;
use Orion\Storage\Table;
use Orion\Storage\Identifier;
use Orion\Storage\Storage;
use Orion\Support\Assert;
use Override;
use Throwable;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_numeric;
use function sprintf;
use function usort;

/**
 * A {@see Storage} over PHP arrays with the full contract: the criteria grammar (equality, IN, IS NULL,
 * {@see Condition}), ordering, paging, identifier and direction checks, refusal of unbounded updates and deletes,
 * and transactions that restore a snapshot when the callable throws, which makes nested transactions savepoints.
 * Compares as SQL would: numbers numerically whatever their PHP type, dates as instants. Tables need no installing;
 * inserts are checked against the primary and unique keys of the tables it is given (Orion's own by default).
 */
final class InMemoryStorage implements Storage
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $tables = [];
    /** @var array<string, list<list<string>>> table => its primary key and unique indexes, by column */
    private readonly array $keys;

    /**
     * @param list<Table> $tables the tables whose keys inserts are checked against: Orion's own by default
     */
    public function __construct(array $tables = [])
    {
        $keys = [];
        foreach ($tables === [] ? Schema::tables() : $tables as $table) {
            $primary = array_map(static fn(Column $column): string => $column->name, $table->primaryKey());
            $unique = array_map(static fn(Index $index): array => $index->columns, array_values(array_filter($table->indexes, static fn(Index $index): bool => $index->unique)));
            $keys[$table->name] = $primary === [] ? $unique : [$primary, ...$unique];
        }
        $this->keys = $keys;
    }

    #[Override]
    public function findOne(string $table, array $criteria): ?array
    {
        return $this->findMany($table, $criteria, null, 1)[0] ?? null;
    }

    #[Override]
    public function findMany(string $table, array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
    {
        self::assertIdentifiers($table, $criteria);
        foreach ($orderBy ?? [] as $column => $direction) {
            Identifier::assert($column);
            if ($direction !== 'asc' && $direction !== 'desc') {
                throw new InvalidArgumentException(sprintf('An order direction is "asc" or "desc", not %s.', Assert::show($direction)));
            }
        }
        if (($limit ?? 0) < 0 || ($offset ?? 0) < 0) {
            throw new InvalidArgumentException('A limit or offset cannot be negative.');
        }

        $rows = [];
        foreach ($this->tables[$table] ?? [] as $row) {
            if (self::matches($row, $criteria)) {
                $rows[] = $row;
            }
        }
        if ($orderBy !== null && $orderBy !== []) {
            usort($rows, static function (array $a, array $b) use ($orderBy): int {
                foreach ($orderBy as $column => $direction) {
                    $order = self::compare($a[$column] ?? null, $b[$column] ?? null);
                    if ($order !== 0) {
                        return $direction === 'desc' ? -$order : $order;
                    }
                }

                return 0;
            });
        }

        return array_slice($rows, $offset ?? 0, $limit);
    }

    #[Override]
    public function insert(string $table, array $row): void
    {
        self::assertIdentifiers($table, $row);
        foreach ($this->keys[$table] ?? [] as $key) {
            $criteria = [];
            foreach ($key as $column) {
                $criteria[$column] = $row[$column] ?? null;
            }
            // As in SQL, a key with a NULL in it never collides.
            if (!in_array(null, $criteria, true) && $this->findOne($table, $criteria) !== null) {
                throw new DuplicateKey(sprintf('%s already holds a row with that %s.', $table, implode(', ', $key)));
            }
        }
        $this->tables[$table][] = $row;
    }

    #[Override]
    public function update(string $table, array $criteria, array $data): int
    {
        self::assertBounded('update', $criteria);
        self::assertIdentifiers($table, $criteria, $data);
        $affected = 0;
        foreach ($this->tables[$table] ?? [] as $index => $row) {
            if (self::matches($row, $criteria)) {
                $this->tables[$table][$index] = [...$row, ...$data];
                ++$affected;
            }
        }

        return $affected;
    }

    #[Override]
    public function delete(string $table, array $criteria): int
    {
        self::assertBounded('delete', $criteria);
        self::assertIdentifiers($table, $criteria);
        $kept = [];
        foreach ($this->tables[$table] ?? [] as $row) {
            if (!self::matches($row, $criteria)) {
                $kept[] = $row;
            }
        }
        $affected = count($this->tables[$table] ?? []) - count($kept);
        $this->tables[$table] = $kept;

        return $affected;
    }

    #[Override]
    public function count(string $table, array $criteria): int
    {
        return count($this->findMany($table, $criteria));
    }

    #[Override]
    public function increment(string $table, array $criteria, string $column): ?int
    {
        self::assertBounded('increment', $criteria);
        self::assertIdentifiers($table, $criteria, [$column => null]);
        $matched = [];
        foreach ($this->tables[$table] ?? [] as $index => $row) {
            if (self::matches($row, $criteria)) {
                $matched[] = $index;
            }
        }
        if (count($matched) > 1) {
            throw new LogicException(sprintf('An increment changes one row; %d match.', count($matched)));
        }
        if ($matched === []) {
            return null;
        }
        $value = (int) ($this->tables[$table][$matched[0]][$column] ?? 0) + 1;
        $this->tables[$table][$matched[0]][$column] = $value;

        return $value;
    }

    #[Override]
    public function transaction(callable $fn): mixed
    {
        $snapshot = $this->tables;
        try {
            return $fn();
        } catch (Throwable $exception) {
            $this->tables = $snapshot;

            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> ...$columnMaps
     */
    private static function assertIdentifiers(string $table, array ...$columnMaps): void
    {
        Identifier::assert($table);
        foreach ($columnMaps as $columns) {
            foreach (array_keys($columns) as $column) {
                Identifier::assert((string) $column);
            }
        }
    }

    /**
     * @param array<string, mixed> $criteria
     */
    private static function assertBounded(string $operation, array $criteria): void
    {
        if ($criteria === []) {
            throw new InvalidArgumentException(sprintf('An %s needs criteria; an unbounded one is refused.', $operation));
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $criteria
     */
    private static function matches(array $row, array $criteria): bool
    {
        foreach ($criteria as $column => $expected) {
            $actual = array_key_exists($column, $row) ? $row[$column] : null;
            $matches = match (true) {
                $expected instanceof Condition => self::satisfies($actual, $expected),
                is_array($expected) => $actual !== null && self::in($actual, $expected),
                $expected === null => $actual === null,
                default => $actual !== null && self::compare($actual, $expected) === 0,
            };
            if (!$matches) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<mixed> $values
     */
    private static function in(mixed $actual, array $values): bool
    {
        foreach ($values as $value) {
            if ($value !== null && self::compare($actual, $value) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function satisfies(mixed $actual, Condition $condition): bool
    {
        if ($condition->operator === Condition::NOT_NULL) {
            return $actual !== null;
        }
        if ($actual === null || $condition->value === null) {
            return false;
        }
        $order = self::compare($actual, $condition->value);

        return match ($condition->operator) {
            Condition::LT => $order < 0,
            Condition::LTE => $order <= 0,
            Condition::GT => $order > 0,
            Condition::GTE => $order >= 0,
            Condition::NE => $order !== 0,
            default => false,
        };
    }

    /**
     * Nulls sort first. Numbers compare numerically whatever their PHP type (a driver may return `'2'` for 2).
     */
    private static function compare(mixed $a, mixed $b): int
    {
        if ($a === null || $b === null) {
            return $a === null ? ($b === null ? 0 : -1) : 1;
        }
        $a = self::normalise($a);
        $b = self::normalise($b);
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a <=> (float) $b;
        }

        return $a <=> $b;
    }

    /**
     * Dates become sortable UTC strings, so the same instant in two zones is equal; booleans become 0 and 1.
     */
    private static function normalise(mixed $value): mixed
    {
        return match (true) {
            $value instanceof DateTimeInterface => DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            is_bool($value) => (int) $value,
            default => $value,
        };
    }
}
