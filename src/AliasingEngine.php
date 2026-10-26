<?php

declare(strict_types=1);

namespace Orion\Testing;

use Orion\Adapter\Capability;
use Orion\Adapter\InMemory\InMemoryEngine;
use Orion\Adapter\PendingTask;
use Orion\Adapter\SchemaAdapter;
use Orion\Adapter\SearchAdapter;
use Orion\Adapter\WriteAdapter;
use Orion\IndexName;
use Orion\Query\Filter;
use Orion\Query\Query;
use Orion\Query\ResultSet;
use Orion\Schema\IndexSchema;
use Orion\Schema\LiveSchema;
use Orion\Schema\MigrationPlan;
use Orion\Write\BulkResult;
use Orion\Write\Document;
use Orion\Write\Tombstone;
use RuntimeException;

/**
 * The in-memory engine with native aliases: it resolves an alias to the physical index it was last pointed at, records
 * what it was asked to alias, and fails on demand. For exercising the native-alias path without a server.
 */
final class AliasingEngine implements SearchAdapter, SchemaAdapter, WriteAdapter
{
    /** @var array<string, string> alias => physical */
    public array $aliases = [];
    public bool $failNextAlias = false;
    public bool $failNextEnsure = false;
    public bool $failNextDrop = false;

    public function __construct(private readonly InMemoryEngine $inner = new InMemoryEngine())
    {
    }

    public function alias(IndexName $alias, IndexName $physical): void
    {
        if ($this->failNextAlias) {
            $this->failNextAlias = false;
            throw new RuntimeException('The engine refused the alias.');
        }
        $this->aliases[$alias->value] = $physical->value;
    }

    public function resolve(IndexName $alias): ?IndexName
    {
        return isset($this->aliases[$alias->value]) ? IndexName::of($this->aliases[$alias->value]) : null;
    }

    public function supports(Capability $capability): bool
    {
        return $capability === Capability::NativeAliases || $this->inner->supports($capability);
    }

    public function search(IndexName $index, Query $query): ResultSet
    {
        return $this->inner->search($this->target($index), $query);
    }

    public function raw(IndexName $index, callable $native, Filter ...$scope): ResultSet
    {
        return $this->inner->raw($this->target($index), $native, ...$scope);
    }

    public function ensure(IndexSchema $schema): void
    {
        if ($this->failNextEnsure) {
            $this->failNextEnsure = false;
            throw new RuntimeException('The engine refused to create the index.');
        }
        $this->inner->ensure($schema);
    }

    public function inspect(IndexName $index): ?LiveSchema
    {
        return $this->inner->inspect($this->target($index));
    }

    public function plan(IndexSchema $desired): MigrationPlan
    {
        return $this->inner->plan($desired);
    }

    public function apply(MigrationPlan $plan): PendingTask
    {
        return $this->inner->apply($plan);
    }

    public function drop(IndexName $index): void
    {
        if ($this->failNextDrop) {
            $this->failNextDrop = false;
            throw new RuntimeException('The engine refused to drop the index.');
        }
        $this->inner->drop($index);
    }

    public function index(IndexName $index, Document ...$docs): BulkResult
    {
        return $this->inner->index($this->target($index), ...$docs);
    }

    public function delete(IndexName $index, Tombstone ...$tombstones): BulkResult
    {
        return $this->inner->delete($this->target($index), ...$tombstones);
    }

    public function flush(IndexName $index, bool $await = false): void
    {
        $this->inner->flush($this->target($index), $await);
    }

    private function target(IndexName $index): IndexName
    {
        return $this->resolve($index) ?? $index;
    }
}
