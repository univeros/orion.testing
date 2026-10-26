<?php

declare(strict_types=1);

namespace Orion\Testing;

use Orion\Storage\Storage;
use Override;

use function sprintf;

/**
 * The fault-injection harness's database (v1 §18.4): any storage, killed on demand. {@see killOnWrite()} dies on the
 * next write to a table, inside its transaction, so the transaction rolls back; {@see killAfterCommit()} lets the next
 * transaction that writes to a table commit and then dies, so the caller never hears that it did. Each fault fires
 * once.
 */
final class FaultyStorage implements Storage
{
    private ?string $killOnWrite = null;
    private ?string $killAfterCommit = null;
    private int $depth = 0;
    private bool $wroteWatched = false;
    /** @var array<string, callable(): void> */
    private array $hooks = [];

    public function __construct(private readonly Storage $inner)
    {
    }

    public function killOnWrite(string $table): void
    {
        $this->killOnWrite = $table;
    }

    public function killAfterCommit(string $table): void
    {
        $this->killAfterCommit = $table;
    }

    /**
     * Runs `$hook` once, just before the next write to the table: another process committing in between.
     *
     * @param callable(): void $hook
     */
    public function beforeNextWrite(string $table, callable $hook): void
    {
        $this->hooks[$table] = $hook;
    }

    #[Override]
    public function findOne(string $table, array $criteria): ?array
    {
        return $this->inner->findOne($table, $criteria);
    }

    #[Override]
    public function findMany(string $table, array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
    {
        return $this->inner->findMany($table, $criteria, $orderBy, $limit, $offset);
    }

    #[Override]
    public function insert(string $table, array $row): void
    {
        $this->writing($table);
        $this->inner->insert($table, $row);
    }

    #[Override]
    public function update(string $table, array $criteria, array $data): int
    {
        $this->writing($table);

        return $this->inner->update($table, $criteria, $data);
    }

    #[Override]
    public function delete(string $table, array $criteria): int
    {
        $this->writing($table);

        return $this->inner->delete($table, $criteria);
    }

    #[Override]
    public function count(string $table, array $criteria): int
    {
        return $this->inner->count($table, $criteria);
    }

    #[Override]
    public function increment(string $table, array $criteria, string $column): ?int
    {
        $this->writing($table);

        return $this->inner->increment($table, $criteria, $column);
    }

    #[Override]
    public function transaction(callable $fn): mixed
    {
        if ($this->depth === 0) {
            $this->wroteWatched = false;
        }
        ++$this->depth;
        try {
            $result = $this->inner->transaction($fn);
        } finally {
            --$this->depth;
        }
        if ($this->depth === 0 && $this->wroteWatched && $this->killAfterCommit !== null) {
            $table = $this->killAfterCommit;
            $this->killAfterCommit = null;

            throw new StorageKilled(sprintf('The database died after committing a write to %s; the caller never heard back.', $table));
        }

        return $result;
    }

    private function writing(string $table): void
    {
        if (isset($this->hooks[$table])) {
            $hook = $this->hooks[$table];
            unset($this->hooks[$table]);
            $hook();
        }
        if ($this->killOnWrite === $table) {
            $this->killOnWrite = null;

            throw new StorageKilled(sprintf('The database died writing to %s.', $table));
        }
        if ($this->killAfterCommit === $table) {
            $this->wroteWatched = true;
        }
    }
}
