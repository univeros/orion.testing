<?php

declare(strict_types=1);

namespace Orion\Testing;

use Orion\Adapter\Capability;
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
use Override;

use function array_slice;
use function sprintf;

/**
 * The fault-injection harness's engine (v1 §18.4): any engine, killed on demand. {@see killDuringBulk()} lets a bulk
 * write reach the engine for its first documents and then dies; {@see killBefore()} dies before a verb acts;
 * {@see killAfter()} lets the verb act and then dies, so the caller never hears that it succeeded (an alias swap
 * acknowledged by the engine but lost on the way back). Each fault fires once; {@see revive()} clears what is left.
 * {@see onBulk()} runs a callback before each bulk write reaches the engine: the application writing while Orion works.
 */
final class FaultyEngine implements SearchAdapter, SchemaAdapter, WriteAdapter
{
    public const string INDEX = 'index';
    public const string DELETE = 'delete';
    public const string ALIAS = 'alias';
    public const string ENSURE = 'ensure';
    public const string APPLY = 'apply';
    public const string DROP = 'drop';

    /** @var array<string, int> verb => calls to let through before dying */
    private array $before = [];
    /** @var array<string, int> verb => calls to let through before acting and then dying */
    private array $after = [];
    private ?int $bulkSurvivors = null;
    /** @var array<string, int> */
    private array $calls = [];
    /** @var (callable(IndexName, int): void)|null */
    private $onBulk = null;

    public function __construct(private readonly SearchAdapter&SchemaAdapter&WriteAdapter $inner)
    {
    }

    public function killBefore(string $verb, int $survivingCalls = 0): void
    {
        $this->before[$verb] = $survivingCalls;
    }

    public function killAfter(string $verb, int $survivingCalls = 0): void
    {
        $this->after[$verb] = $survivingCalls;
    }

    /**
     * The next bulk write sends its first `$documents` documents and dies.
     */
    public function killDuringBulk(int $documents): void
    {
        $this->bulkSurvivors = $documents;
    }

    /**
     * @param (callable(IndexName $index, int $call): void)|null $hook called with the target and the bulk write's number for that target, from 1
     */
    public function onBulk(?callable $hook): void
    {
        $this->onBulk = $hook;
    }

    public function revive(): void
    {
        $this->before = [];
        $this->after = [];
        $this->bulkSurvivors = null;
    }

    public function calls(string $verb): int
    {
        return $this->calls[$verb] ?? 0;
    }

    #[Override]
    public function supports(Capability $capability): bool
    {
        return $this->inner->supports($capability);
    }

    #[Override]
    public function search(IndexName $index, Query $query): ResultSet
    {
        return $this->inner->search($index, $query);
    }

    #[Override]
    public function raw(IndexName $index, callable $native, Filter ...$scope): ResultSet
    {
        return $this->inner->raw($index, $native, ...$scope);
    }

    #[Override]
    public function ensure(IndexSchema $schema): void
    {
        $this->around(self::ENSURE, fn() => $this->inner->ensure($schema));
    }

    #[Override]
    public function inspect(IndexName $index): ?LiveSchema
    {
        return $this->inner->inspect($index);
    }

    #[Override]
    public function plan(IndexSchema $desired): MigrationPlan
    {
        return $this->inner->plan($desired);
    }

    #[Override]
    public function apply(MigrationPlan $plan): PendingTask
    {
        return $this->around(self::APPLY, fn(): PendingTask => $this->inner->apply($plan));
    }

    #[Override]
    public function drop(IndexName $index): void
    {
        $this->around(self::DROP, fn() => $this->inner->drop($index));
    }

    #[Override]
    public function alias(IndexName $alias, IndexName $physical): void
    {
        $this->around(self::ALIAS, fn() => $this->inner->alias($alias, $physical));
    }

    #[Override]
    public function index(IndexName $index, Document ...$docs): BulkResult
    {
        $this->calls[self::INDEX . ':' . $index->value] = ($this->calls[self::INDEX . ':' . $index->value] ?? 0) + 1;
        if ($this->onBulk !== null) {
            ($this->onBulk)($index, $this->calls[self::INDEX . ':' . $index->value]);
        }
        if ($this->bulkSurvivors !== null && $docs !== []) {
            $survivors = $this->bulkSurvivors;
            $this->bulkSurvivors = null;
            $this->inner->index($index, ...array_slice($docs, 0, $survivors));

            throw new EngineKilled(sprintf('The engine died after %d of %d documents.', $survivors, \count($docs)));
        }

        return $this->around(self::INDEX, fn(): BulkResult => $this->inner->index($index, ...$docs));
    }

    #[Override]
    public function delete(IndexName $index, Tombstone ...$tombstones): BulkResult
    {
        return $this->around(self::DELETE, fn(): BulkResult => $this->inner->delete($index, ...$tombstones));
    }

    #[Override]
    public function flush(IndexName $index, bool $await = false): void
    {
        $this->inner->flush($index, $await);
    }

    /**
     * @template R
     * @param callable(): R $call
     * @return R
     */
    private function around(string $verb, callable $call): mixed
    {
        $this->calls[$verb] = ($this->calls[$verb] ?? 0) + 1;
        if (isset($this->before[$verb]) && $this->before[$verb]-- === 0) {
            unset($this->before[$verb]);

            throw new EngineKilled(sprintf('The engine died before %s.', $verb));
        }
        $result = $call();
        if (isset($this->after[$verb]) && $this->after[$verb]-- === 0) {
            unset($this->after[$verb]);

            throw new EngineKilled(sprintf('The engine died after %s; the caller never heard back.', $verb));
        }

        return $result;
    }
}
