<?php

declare(strict_types=1);

namespace Orion\Testing;

use DateTimeImmutable;
use Orion\Adapter\Capability;
use Orion\Adapter\SchemaAdapter;
use Orion\Adapter\SearchAdapter;
use Orion\Adapter\WriteAdapter;
use Orion\IndexName;
use Orion\Orion;
use Orion\Query\Query;
use Orion\Receipt\SodiumSigner;
use Orion\Registry\EngineRegistry;
use Orion\Schema\IndexSchema;
use Orion\Storage\Schema;
use Orion\Storage\Storage;
use Orion\Tenancy\Tenancy;
use Orion\Wiring\Config;
use Orion\Wiring\Graph;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function bin2hex;
use function ksort;
use function random_bytes;
use function str_repeat;

/**
 * The fault-injection harness (v1 §18.4, revision §7 WP4): the engine is killed mid-bulk, mid-reindex and mid-swap,
 * and the database mid-swap, while the application keeps writing; after recovery, every document is in the index the
 * alias points at in its latest state, deleted ones included, and the receipt chain verifies. Recovery is what an
 * operator does: run the drain or the reindex again.
 *
 * On an engine without native aliases the swap never reaches the engine, so the engine-side swap faults become the
 * database-side ones: there, a swap is a row write and nothing else.
 */
abstract class FaultInjectionTestCase extends TestCase
{
    public const string ENGINE = 'engine';
    private const string KEY = 'faults';

    private FaultyEngine $engine;
    private FaultyStorage $storage;
    private IndexName $alias;
    private Graph $graph;

    abstract protected function createEngine(): SearchAdapter&SchemaAdapter&WriteAdapter;

    /**
     * A fresh, empty storage with Orion's tables, for each run: in memory by default; a storage adapter's own suite
     * (the Univeros module's Cycle storage, say) runs the harness on itself by overriding this.
     */
    protected function createStorage(): Storage
    {
        return new InMemoryStorage();
    }

    protected function setUp(): void
    {
        $this->engine = new FaultyEngine($this->createEngine());
        $this->storage = new FaultyStorage($this->createStorage());
        $this->alias = IndexName::of('orion_faults_' . bin2hex(random_bytes(6)));
        $this->graph = Orion::create(new Config(
            storage: $this->storage,
            engines: new EngineRegistry(...[self::ENGINE => $this->engine]),
            tenancy: Tenancy::single(),
            clock: new SettableClock(new DateTimeImmutable('2026-10-26 12:00:00+00:00')),
            receiptSigner: SodiumSigner::fromSeed(str_repeat("\x03", 32), self::KEY),
        ))->graph();
    }

    protected function tearDown(): void
    {
        if (isset($this->engine)) {
            $this->engine->revive();
            $this->engine->onBulk(null);
            foreach ([1, 2, 3] as $version) {
                $this->engine->drop(IndexName::of($this->alias->value . '_v' . $version));
            }
        }
    }

    public function testAnEngineKilledMidBulkLosesNoDocument(): void
    {
        $this->seed();
        $this->graph->outbox()->index($this->alias, 'd7', ['title' => 'Trail shoe 7', 'sku' => 'S7']);
        $this->graph->outbox()->delete($this->alias, 'd1');
        $this->engine->killDuringBulk(documents: 1);

        $this->assertKilled(fn() => $this->graph->drain()->run($this->alias));
        $this->engine->revive();
        $this->graph->drain()->run($this->alias);

        $this->assertNothingLost();
    }

    public function testAnEngineKilledMidReindexLosesNoDocument(): void
    {
        $this->seed();
        $next = $this->physical(2);
        $this->engine->onBulk(function (IndexName $index, int $call) use ($next): void {
            if ($index->equals($next) && $call === 2) {
                $this->engine->onBulk(null);
                $this->graph->outbox()->index($this->alias, 'd7', ['title' => 'Trail shoe 7', 'sku' => 'S7']);
                $this->graph->outbox()->delete($this->alias, 'd1');
                $this->graph->drain()->run($this->alias);
                $this->engine->killDuringBulk(documents: 1);
            }
        });

        $this->assertKilled(fn() => $this->graph->reindexer(self::ENGINE)->reindex(self::rebuilt($this->alias), batch: 2));
        self::assertSame(1, $this->graph->aliases()->current($this->alias)?->version, 'Nothing was swapped onto a half-built version.');
        $this->engine->revive();
        $this->graph->reindexer(self::ENGINE)->reindex(self::rebuilt($this->alias), batch: 2);
        $this->graph->drain()->run($this->alias);

        $this->assertNothingLost();
        self::assertSame(2, $this->graph->aliases()->current($this->alias)?->version);
    }

    #[DataProvider('swapFaults')]
    public function testAKillMidSwapLosesNoDocument(string $fault): void
    {
        $this->seed();
        $native = $this->engine->supports(Capability::NativeAliases);
        match ($fault) {
            'engine dies before swapping' => $native ? $this->engine->killBefore(FaultyEngine::ALIAS) : $this->storage->killOnWrite(Schema::ALIAS),
            'engine swaps and dies' => $native ? $this->engine->killAfter(FaultyEngine::ALIAS) : $this->storage->killAfterCommit(Schema::ALIAS),
            'database dies writing the alias' => $this->storage->killOnWrite(Schema::ALIAS),
            'database commits the alias and dies' => $this->storage->killAfterCommit(Schema::ALIAS),
            default => throw new RuntimeException('Unknown fault ' . $fault),
        };

        $this->assertKilled(fn() => $this->graph->reindexer(self::ENGINE)->reindex(self::rebuilt($this->alias)));
        // The application keeps writing; the drain sends its writes wherever the alias resolves now.
        $this->graph->outbox()->index($this->alias, 'd7', ['title' => 'Trail shoe 7', 'sku' => 'S7']);
        $this->graph->outbox()->delete($this->alias, 'd1');
        $this->graph->drain()->run($this->alias);
        $this->graph->reindexer(self::ENGINE)->reindex(self::rebuilt($this->alias));
        $this->graph->drain()->run($this->alias);

        $this->assertNothingLost();
        self::assertSame(2, $this->graph->aliases()->current($this->alias)?->version);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function swapFaults(): iterable
    {
        foreach (['engine dies before swapping', 'engine swaps and dies', 'database dies writing the alias', 'database commits the alias and dies'] as $fault) {
            yield $fault => [$fault];
        }
    }

    /**
     * Version 1 with d1 to d6 written, d3 deleted, drained.
     */
    private function seed(): void
    {
        $this->graph->planner(self::ENGINE)->apply(self::schema($this->alias));
        for ($i = 1; $i <= 6; ++$i) {
            $this->graph->outbox()->index($this->alias, 'd' . $i, ['title' => 'Trail shoe ' . $i, 'sku' => 'S' . $i]);
        }
        $this->graph->outbox()->delete($this->alias, 'd3');
        $this->graph->drain()->run($this->alias);
    }

    /**
     * After d7 was added and d1 deleted: the index behind the alias holds exactly the latest state of every document,
     * at most one reindex was receipted, and the chain verifies.
     */
    private function assertNothingLost(): void
    {
        $target = $this->graph->aliases()->target($this->alias);
        $this->engine->flush($target, await: true);
        $documents = [];
        foreach ($this->engine->search($target, Query::on($this->alias->value)->paginate(1, 100))->hits as $hit) {
            $documents[$hit->id] = $hit->source['sku'] ?? null;
        }
        ksort($documents);

        self::assertSame(['d2' => 'S2', 'd4' => 'S4', 'd5' => 'S5', 'd6' => 'S6', 'd7' => 'S7'], $documents);
        self::assertLessThanOrEqual(1, $this->storage->count(Schema::RECEIPT, ['operation' => 'reindexed']));
        $verification = $this->graph->verifier()->verify();
        self::assertSame([$this->graph->receipts()->head(), []], [$verification->receipts, $verification->breaks]);
        self::assertSame(0, $this->graph->deadLetters()->count($this->alias));
        self::assertSame(0, $this->graph->drain()->status($this->alias)->lag);
    }

    private function assertKilled(callable $run): void
    {
        try {
            $run();
            self::fail('The run survived the fault.');
        } catch (EngineKilled | StorageKilled) {
            self::addToAssertionCount(1);
        }
    }

    private function physical(int $version): IndexName
    {
        return IndexName::of($this->alias->value . '_v' . $version);
    }

    private static function schema(IndexName $alias): IndexSchema
    {
        return IndexSchema::for($alias->value)->text('title', language: 'en')->keyword('sku', filterable: true);
    }

    private static function rebuilt(IndexName $alias): IndexSchema
    {
        return IndexSchema::for($alias->value)->text('title', language: 'en')->keyword('sku', filterable: true, sortable: true);
    }
}
