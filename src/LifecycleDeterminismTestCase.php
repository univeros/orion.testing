<?php

declare(strict_types=1);

namespace Orion\Testing;

use DateTimeImmutable;
use LogicException;
use Orion\Adapter\Capability;
use Orion\Adapter\InMemory\InMemoryEngine;
use Orion\Adapter\SchemaAdapter;
use Orion\Adapter\SearchAdapter;
use Orion\Adapter\WriteAdapter;
use Orion\IndexName;
use Orion\Lifecycle\Operation;
use Orion\Lifecycle\Planner;
use Orion\Orion;
use Orion\Query\Hit;
use Orion\Query\Query;
use Orion\Receipt\Receipt;
use Orion\Receipt\SodiumSigner;
use Orion\Registry\EngineRegistry;
use Orion\Registry\IndexRegistration;
use Orion\Schema\Field;
use Orion\Schema\IndexSchema;
use Orion\Schema\MigrationPlan;
use Orion\Storage\Schema;
use Orion\Storage\Storage;
use Orion\Tenancy\Tenancy;
use Orion\Tenancy\TenantResolver;
use Orion\Tenancy\TenantScope;
use Orion\Wiring\Config;
use Orion\Wiring\Graph;
use Orion\Write\Document;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_reverse;
use function bin2hex;
use function ksort;
use function random_bytes;
use function sort;

/**
 * The determinism harness for the schema lifecycle (v1 §17, §18.3): plan and apply, run repeatedly from the same
 * starting state with the same inputs, produce identical plans, identical outcomes, identical registry rows, an
 * identical live index and identical receipts, and the receipt chain verifies.
 *
 * Every run uses a fresh in-memory storage, a fixed clock and a fixed signing key, on a fresh alias dropped afterwards.
 */
abstract class LifecycleDeterminismTestCase extends TestCase
{
    public const string ENGINE = 'engine';
    private const string OTHER_ENGINE = 'other';
    private const string NATIVE_KEY = 'harness';
    private const string SEED = "\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07";

    private SearchAdapter&SchemaAdapter&WriteAdapter $engine;
    private IndexName $alias;
    private Graph $graph;
    private SettableClock $clock;

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
        $this->engine = $this->createEngine();
        $this->alias = IndexName::of('orion_lifecycle_' . bin2hex(random_bytes(6)));
        $this->graph = $this->freshGraph();
    }

    protected function tearDown(): void
    {
        if (isset($this->engine)) {
            $this->dropVersions();
        }
    }

    public function testPlanAndApplyAreReproducible(): void
    {
        $first = $this->transcript($this->scenario());
        $this->reset();

        $second = $this->transcript($this->scenario());

        self::assertSame($first, $second);
        self::assertTrue($this->graph->verifier()->verify()->ok());
        self::assertNotNull($first[0]['outcome']['receipt']);
        self::assertNull($first[1]['outcome']['receipt'], 'An unchanged apply issues no receipt.');
        self::assertSame(Operation::Created->value, $first[0]['outcome']['operation']);
        self::assertSame(Operation::Unchanged->value, $first[1]['outcome']['operation']);
        self::assertSame(Operation::Applied->value, $first[2]['outcome']['operation']);
        self::assertSame(Operation::ReindexRequired->value, $first[4]['outcome']['operation']);
    }

    public function testAReindexIsReproducibleAndLosesNothing(): void
    {
        $first = $this->reindexTranscript();
        $this->reset();

        $second = $this->reindexTranscript();

        self::assertSame($first, $second);
        self::assertSame(['d2' => 'S2', 'd4' => 'S4', 'd5' => 'S5', 'd6' => 'S6', 'd7' => 'S7'], $first['documents']);
        self::assertSame([$this->physical(1)->value], $first['dropped']);
        self::assertSame([$this->physical(2)->value], $first['registry']);
        self::assertSame(['created', 'reindexed', 'dropped'], array_map(static fn(array $r): mixed => $r['operation'], array_reverse($first['receipts'])));
        self::assertTrue($this->graph->verifier()->verify()->ok());
    }

    public function testApplyingTheSameSchemaAgainChangesNothing(): void
    {
        $this->planner()->apply($this->schema());

        $outcome = $this->planner()->apply($this->schema());

        self::assertSame(Operation::Unchanged, $outcome->operation);
        self::assertTrue($outcome->plan->isEmpty());
        self::assertTrue($this->planner()->plan($this->schema())->isEmpty());
        self::assertCount(1, $this->graph->registry()->versions($this->alias));
        self::assertSame($this->physical(1)->value, $this->graph->aliases()->current($this->alias)?->physical->value);
    }

    public function testCreatingAnIndexRegistersVersionOneBehindTheAlias(): void
    {
        $outcome = $this->planner()->apply($this->schema());

        self::assertSame(Operation::Created, $outcome->operation);
        self::assertSame($this->physical(1)->value, $outcome->registration->physical->value);
        self::assertSame(1, $outcome->registration->version);
        self::assertSame($this->schema()->hash(), $outcome->registration->schema->hash());
        self::assertSame(['sku', 'title'], $this->paths($outcome->plan->additive()));
        self::assertNotNull($this->engine->inspect($this->physical(1)));
        self::assertTrue(MigrationPlan::between($this->schema(), $this->engine->inspect($this->physical(1)))->isEmpty());
        if ($this->engine->supports(Capability::NativeAliases)) {
            self::assertTrue(MigrationPlan::between($this->schema(), $this->engine->inspect($this->alias))->isEmpty(), 'The engine resolves the alias to version 1.');
        }
        self::assertSame([
            'operation' => 'created',
            'index' => $this->alias->value,
            'physical' => $this->physical(1)->value,
            'version' => 1,
            'schema_hash' => $this->schema()->hash(),
            'plan' => $outcome->plan->toArray(),
            'receipt' => $outcome->receipt?->toArray(),
        ], $outcome->toArray());
        self::assertSame([Operation::Created, null, 1, $this->schema()->hash()], [$outcome->receipt?->operation, $outcome->receipt?->fromVersion, $outcome->receipt?->toVersion, $outcome->receipt?->schemaHash]);
    }

    public function testTheAliasTargetReachesTheDocuments(): void
    {
        $this->planner()->apply($this->schema());
        $target = $this->graph->aliases()->target($this->alias);

        $this->engine->index($target, new Document('1', ['title' => 'Trail shoe', 'sku' => 'A1'], 1))->await(30);
        $this->engine->flush($target, await: true);

        self::assertSame(['1'], $this->ids($target, Query::on($this->alias->value)->match('title', 'trail')));
    }

    public function testABreakingChangeIsNeverAppliedInPlace(): void
    {
        $this->planner()->apply($this->schema());
        $desired = IndexSchema::for($this->alias->value)->text('title', language: 'en')->float('sku');

        $outcome = $this->planner()->apply($desired);

        self::assertSame(Operation::ReindexRequired, $outcome->operation);
        self::assertSame(['sku'], $this->paths($outcome->plan->breaking()));
        $this->assertUntouched();
    }

    public function testAnAdditiveChangeTheEngineRefusesRequiresAReindex(): void
    {
        $this->planner()->apply($this->schema());
        $desired = $this->schema()->text('summary', language: 'en', folding: true);

        $outcome = $this->planner()->apply($desired);

        self::assertSame(['summary'], $this->paths($outcome->plan->additive()));
        self::assertSame([], $outcome->plan->breaking(), 'Core classifies a new field as additive; only the engine can refuse it.');
        if ($outcome->operation === Operation::Applied) {
            self::assertTrue($this->planner()->plan($desired)->isEmpty());
            self::assertSame($desired->hash(), $this->graph->registry()->find($this->physical(1))?->schema->hash());

            return;
        }
        self::assertSame(Operation::ReindexRequired, $outcome->operation);
        $this->assertUntouched();
    }

    public function testWhatTheEngineCannotReportIsDiffedAgainstTheRegistration(): void
    {
        // An org key is declared only where tenants are resolved.
        $this->graph = $this->freshGraph(Tenancy::resolvedBy(new class implements TenantResolver {
            public function resolve(): TenantScope
            {
                return new TenantScope('harness');
            }
        }));
        $registered = $this->schema()->orgKey('org_id')->native(self::NATIVE_KEY, ['shards' => 1]);
        $this->planner()->apply($registered);

        $native = $this->planner()->apply($registered->native(self::NATIVE_KEY, ['shards' => 2]));
        $orgKey = $this->planner()->apply($this->schema()->keyword('org_id', filterable: true)->keyword('tenant', filterable: true)->orgKey('tenant')->native(self::NATIVE_KEY, ['shards' => 1]));

        self::assertSame(Operation::ReindexRequired, $native->operation);
        self::assertSame([MigrationPlan::NATIVE . '.' . self::NATIVE_KEY], $this->paths($native->plan->breaking()));
        self::assertSame(Operation::ReindexRequired, $orgKey->operation);
        self::assertSame([MigrationPlan::ORG_KEY], $this->paths($orgKey->plan->breaking()));
        self::assertSame(['tenant'], $this->paths($orgKey->plan->additive()));
        $removedNative = $this->planner()->apply($this->schema()->orgKey('org_id'));
        self::assertSame(Operation::ReindexRequired, $removedNative->operation);
        self::assertSame([MigrationPlan::NATIVE . '.' . self::NATIVE_KEY], $this->paths($removedNative->plan->breaking()));
        try {
            $this->planner()->apply($this->schema()->keyword('org_id', filterable: true)->native(self::NATIVE_KEY, ['shards' => 1]));
            self::fail('A resolved tenancy let an index drop its org key.');
        } catch (LogicException $refused) {
            self::assertStringContainsString('org key', $refused->getMessage());
        }
        self::assertSame($registered->hash(), $this->graph->registry()->find($this->physical(1))?->schema->hash());
        self::assertTrue($this->planner()->plan($registered)->isEmpty());
    }

    public function testAnAliasRowThatDisagreesWithTheRegistryFailsClosed(): void
    {
        $this->planner()->apply($this->schema());
        $this->graph->storage()->update(Schema::ALIAS, ['alias' => $this->alias->value], ['engine' => 'other']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($this->physical(1)->value);

        $this->planner()->plan($this->schema());
    }

    public function testAnAliasNameShapedLikeAPhysicalIndexIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->planner()->apply(IndexSchema::for($this->alias->value . '_v1')->keyword('sku'));
    }

    public function testAQueryTimeChangeRefreshesTheRegistrationWithoutTouchingTheIndex(): void
    {
        $this->planner()->apply($this->schema());
        $boosted = IndexSchema::for($this->alias->value)->text('title', language: 'en', boost: 3.0)->keyword('sku', filterable: true);

        $outcome = $this->planner()->apply($boosted);

        self::assertSame(Operation::Applied, $outcome->operation, 'The registered schema changed, so the apply is receipted.');
        self::assertSame($boosted->hash(), $outcome->receipt?->schemaHash);
        self::assertTrue($outcome->plan->isEmpty());
        self::assertSame($boosted->hash(), $outcome->registration->schema->hash());
        self::assertSame($boosted->hash(), $this->graph->registry()->find($this->physical(1))?->schema->hash(), 'Queries compile from the registered schema, so the boost must be recorded.');
        self::assertSame(Operation::Unchanged, $this->planner()->apply($boosted)->operation);
    }

    public function testARegisteredIndexMissingFromTheEngineFailsClosed(): void
    {
        $this->planner()->apply($this->schema());
        $this->engine->drop($this->physical(1));

        try {
            $this->planner()->plan($this->schema());
            self::fail('A missing physical index was planned as a create.');
        } catch (LogicException $e) {
            self::assertStringContainsString($this->physical(1)->value, $e->getMessage());
        }

        $this->expectException(LogicException::class);

        $this->planner()->apply($this->schema());
    }

    public function testAnIndexBelongsToTheEngineThatBuiltIt(): void
    {
        $this->planner()->apply($this->schema());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(self::ENGINE);

        $this->graph->planner(self::OTHER_ENGINE)->plan($this->schema());
    }

    public function testAPhysicalIndexAlreadyOnTheEngineButNotRegisteredIsRefused(): void
    {
        $this->engine->ensure($this->schema()->named($this->physical(1)));

        try {
            $this->planner()->apply($this->schema());
            self::fail('An unregistered physical index was adopted.');
        } catch (LogicException $e) {
            self::assertStringContainsString($this->physical(1)->value, $e->getMessage());
            self::assertNull($this->graph->aliases()->current($this->alias));
            self::assertSame([], $this->graph->registry()->versions($this->alias), 'Nothing was claimed.');
        }
    }

    public function testAnIndexNamedLikeTheAliasButNotRegisteredIsRefused(): void
    {
        $this->engine->ensure($this->schema());

        try {
            $this->planner()->apply($this->schema());
            self::fail('An unregistered index was shadowed by an alias.');
        } catch (LogicException $e) {
            self::assertStringContainsString($this->alias->value, $e->getMessage());
            self::assertNull($this->engine->inspect($this->physical(1)), 'Nothing was created.');
        } finally {
            $this->engine->drop($this->alias);
        }
    }

    // --- the scenario -------------------------------------------------------------------------------------------

    /**
     * The same sequence of desired schemas every run compares: create, no-op, additive, additive-or-refused, breaking.
     *
     * @return list<IndexSchema>
     */
    private function scenario(): array
    {
        return [
            $this->schema(),
            $this->schema(),
            $this->schema()->keyword('color', filterable: true),
            $this->schema()->keyword('color', filterable: true)->text('summary', language: 'en', folding: true),
            IndexSchema::for($this->alias->value)->text('title', language: 'en')->float('sku')->keyword('color', filterable: true),
        ];
    }

    /**
     * @param list<IndexSchema> $schemas
     * @return list<array<string, mixed>>
     */
    private function transcript(array $schemas): array
    {
        $transcript = [];
        foreach ($schemas as $desired) {
            $plan = $this->planner()->plan($desired);
            $outcome = $this->planner()->apply($desired);
            $live = $this->engine->inspect($outcome->registration->physical);
            $transcript[] = [
                'plan' => $plan->toArray(),
                'outcome' => $outcome->toArray(),
                'live' => $live === null ? null : Field::mapToArray($live->fields),
                'registry' => array_map(static fn(IndexRegistration $r): array => [...$r->toRow(), 'created_at' => $r->createdAt->format(DateTimeImmutable::ATOM)], $this->graph->registry()->versions($this->alias)),
                'alias' => array_map(static fn(mixed $v): mixed => $v instanceof DateTimeImmutable ? $v->format(DateTimeImmutable::ATOM) : $v, $this->graph->storage()->findOne(Schema::ALIAS, ['alias' => $this->alias->value]) ?? []),
                'receipts' => array_map(static fn(Receipt $r): array => $r->toArray(), $this->graph->receipts()->of($this->alias)),
            ];
        }

        return $transcript;
    }

    /**
     * Create, write and drain, reindex with a breaking change in small batches, write and drain again, then prune
     * after the grace window: everything a run leaves behind, comparable across runs.
     *
     * @return array{reindex: array<string, mixed>, documents: array<string, mixed>, dropped: list<string>, registry: list<string>, receipts: list<array<string, mixed>>, live: array<string, mixed>|null}
     */
    private function reindexTranscript(): array
    {
        $this->planner()->apply($this->schema());
        $outbox = $this->graph->outbox();
        for ($i = 1; $i <= 6; ++$i) {
            $outbox->index($this->alias, 'd' . $i, ['title' => 'Trail shoe ' . $i, 'sku' => 'S' . $i]);
        }
        $outbox->delete($this->alias, 'd3');
        $this->graph->drain()->run($this->alias);
        $breaking = IndexSchema::for($this->alias->value)->text('title', language: 'en')->keyword('sku', filterable: true, sortable: true);

        $result = $this->graph->reindexer(self::ENGINE)->reindex($breaking, batch: 2);
        $outbox->index($this->alias, 'd7', ['title' => 'Trail shoe 7', 'sku' => 'S7']);
        $outbox->delete($this->alias, 'd1');
        $this->graph->drain()->run($this->alias);
        $this->clock->advance('+301 seconds');
        $dropped = $this->graph->reindexer(self::ENGINE)->prune($this->alias, graceSeconds: 300);

        $target = $this->graph->aliases()->target($this->alias);
        $this->engine->flush($target, await: true);
        $documents = [];
        foreach ($this->engine->search($target, Query::on($this->alias->value)->paginate(1, 100))->hits as $hit) {
            $documents[$hit->id] = $hit->source['sku'] ?? null;
        }
        ksort($documents);
        $live = $this->engine->inspect($this->physical(2));

        return [
            'reindex' => $result->toArray(),
            'documents' => $documents,
            'dropped' => array_map(static fn(IndexRegistration $r): string => $r->physical->value, $dropped),
            'registry' => array_map(static fn(IndexRegistration $r): string => $r->physical->value, $this->graph->registry()->versions($this->alias)),
            'receipts' => array_map(static fn(Receipt $r): array => $r->toArray(), $this->graph->receipts()->of($this->alias)),
            'live' => $live === null ? null : Field::mapToArray($live->fields),
        ];
    }

    private function reset(): void
    {
        $this->dropVersions();
        $this->graph = $this->freshGraph();
    }

    private function dropVersions(): void
    {
        foreach ([1, 2, 3] as $version) {
            $this->engine->drop($this->physical($version));
        }
    }

    private function freshGraph(?Tenancy $tenancy = null): Graph
    {
        return Orion::create(new Config(
            storage: $this->createStorage(),
            engines: new EngineRegistry(...[self::ENGINE => $this->engine, self::OTHER_ENGINE => new InMemoryEngine()]),
            tenancy: $tenancy ?? Tenancy::single(),
            clock: $this->clock = new SettableClock(new DateTimeImmutable('2026-10-26 12:00:00+00:00')),
            receiptSigner: SodiumSigner::fromSeed(self::SEED, self::NATIVE_KEY),
        ))->graph();
    }

    private function planner(): Planner
    {
        return $this->graph->planner(self::ENGINE);
    }

    private function schema(): IndexSchema
    {
        return IndexSchema::for($this->alias->value)->text('title', language: 'en')->keyword('sku', filterable: true);
    }

    private function physical(int $version): IndexName
    {
        return IndexName::of($this->alias->value . '_v' . $version);
    }

    private function assertUntouched(): void
    {
        self::assertTrue(MigrationPlan::between($this->schema(), $this->engine->inspect($this->physical(1)))->isEmpty(), 'The live index changed.');
        self::assertSame($this->schema()->hash(), $this->graph->registry()->find($this->physical(1))?->schema->hash(), 'The registration changed.');
        self::assertSame($this->physical(1)->value, $this->graph->aliases()->current($this->alias)?->physical->value, 'The alias moved.');
        self::assertSame(1, $this->graph->storage()->count(Schema::INDEX, ['alias' => $this->alias->value]));
    }

    /**
     * @param list<\Orion\Schema\Change> $changes
     * @return list<string>
     */
    private function paths(array $changes): array
    {
        return array_map(static fn($change): string => $change->path, $changes);
    }

    /**
     * @return list<string>
     */
    private function ids(IndexName $target, Query $query): array
    {
        $ids = array_map(static fn(Hit $hit): string => $hit->id, $this->engine->search($target, $query)->hits);
        sort($ids);

        return $ids;
    }
}
