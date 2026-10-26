<?php

declare(strict_types=1);

namespace Orion\Testing;

use DateTimeImmutable;
use LogicException;
use Orion\Adapter\InMemory\InMemoryEngine;
use Orion\Adapter\SchemaAdapter;
use Orion\Adapter\SearchAdapter;
use Orion\Adapter\WriteAdapter;
use Orion\IndexName;
use Orion\Lifecycle\Operation;
use Orion\Lifecycle\Outcome;
use Orion\Lifecycle\Planner;
use Orion\Orion;
use Orion\Query\Hit;
use Orion\Query\Query;
use Orion\Registry\EngineRegistry;
use Orion\Registry\IndexRegistration;
use Orion\Schema\Field;
use Orion\Schema\IndexSchema;
use Orion\Schema\MigrationPlan;
use Orion\Storage\Schema;
use Orion\Tenancy\Tenancy;
use Orion\Wiring\Config;
use Orion\Wiring\Graph;
use Orion\Write\Document;
use PHPUnit\Framework\TestCase;

use function array_map;
use function bin2hex;
use function random_bytes;
use function sort;

/**
 * The determinism harness for the schema lifecycle (v1 §17, §18.3): plan and apply, run repeatedly from the same
 * starting state with the same inputs, produce identical plans, identical outcomes, identical registry rows and an
 * identical live index. Receipts (WP4) will be signed over exactly the {@see Outcome} this harness compares, so
 * "identical receipts" is the one assertion still to add.
 *
 * Every run uses a fresh in-memory storage and a fixed clock, on a fresh alias dropped afterwards.
 */
abstract class LifecycleDeterminismTestCase extends TestCase
{
    public const string ENGINE = 'engine';
    private const string OTHER_ENGINE = 'other';
    private const string NATIVE_KEY = 'harness';

    private SearchAdapter&SchemaAdapter&WriteAdapter $engine;
    private IndexName $alias;
    private Graph $graph;

    abstract protected function createEngine(): SearchAdapter&SchemaAdapter&WriteAdapter;

    protected function setUp(): void
    {
        $this->engine = $this->createEngine();
        $this->alias = IndexName::of('orion_lifecycle_' . bin2hex(random_bytes(6)));
        $this->graph = $this->freshGraph();
    }

    protected function tearDown(): void
    {
        if (isset($this->engine)) {
            $this->engine->drop($this->physical(1));
        }
    }

    public function testPlanAndApplyAreReproducible(): void
    {
        $first = $this->transcript($this->scenario());
        $this->reset();

        $second = $this->transcript($this->scenario());

        self::assertSame($first, $second);
        self::assertSame(Operation::Created->value, $first[0]['outcome']['operation']);
        self::assertSame(Operation::Unchanged->value, $first[1]['outcome']['operation']);
        self::assertSame(Operation::Applied->value, $first[2]['outcome']['operation']);
        self::assertSame(Operation::ReindexRequired->value, $first[4]['outcome']['operation']);
    }

    public function testApplyingTheSameSchemaAgainChangesNothing(): void
    {
        $this->planner()->apply($this->schema());

        $outcome = $this->planner()->apply($this->schema());

        self::assertSame(Operation::Unchanged, $outcome->operation);
        self::assertTrue($outcome->plan->isEmpty());
        self::assertTrue($this->planner()->plan($this->schema())->isEmpty());
        self::assertCount(1, $this->graph->registry()->versions($this->alias));
        self::assertSame($this->physical(1)->value, $this->graph->aliases()->current($this->alias)?->value);
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
        self::assertSame([
            'operation' => 'created',
            'index' => $this->alias->value,
            'physical' => $this->physical(1)->value,
            'version' => 1,
            'schema_hash' => $this->schema()->hash(),
            'plan' => $outcome->plan->toArray(),
        ], $outcome->toArray());
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
        $registered = $this->schema()->orgKey('org_id')->native(self::NATIVE_KEY, ['shards' => 1]);
        $this->planner()->apply($registered);

        $native = $this->planner()->apply($registered->native(self::NATIVE_KEY, ['shards' => 2]));
        $orgKey = $this->planner()->apply($this->schema()->keyword('org_id', filterable: true)->keyword('tenant', filterable: true)->orgKey('tenant')->native(self::NATIVE_KEY, ['shards' => 1]));

        self::assertSame(Operation::ReindexRequired, $native->operation);
        self::assertSame([MigrationPlan::NATIVE . '.' . self::NATIVE_KEY], $this->paths($native->plan->breaking()));
        self::assertSame(Operation::ReindexRequired, $orgKey->operation);
        self::assertSame([MigrationPlan::ORG_KEY], $this->paths($orgKey->plan->breaking()));
        self::assertSame(['tenant'], $this->paths($orgKey->plan->additive()));
        self::assertSame($registered->hash(), $this->graph->registry()->find($this->physical(1))?->schema->hash());
        self::assertTrue($this->planner()->plan($registered)->isEmpty());
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
            self::assertSame([], $this->graph->registry()->versions($this->alias));
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
                'alias' => $this->graph->aliases()->current($this->alias)?->value,
            ];
        }

        return $transcript;
    }

    private function reset(): void
    {
        $this->engine->drop($this->physical(1));
        $this->graph = $this->freshGraph();
    }

    private function freshGraph(): Graph
    {
        return Orion::create(new Config(
            storage: new InMemoryStorage(),
            engines: new EngineRegistry(...[self::ENGINE => $this->engine, self::OTHER_ENGINE => new InMemoryEngine()]),
            tenancy: Tenancy::single(),
            clock: new FixedClock(new DateTimeImmutable('2026-10-26 12:00:00')),
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
        self::assertSame($this->physical(1)->value, $this->graph->aliases()->current($this->alias)?->value, 'The alias moved.');
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
