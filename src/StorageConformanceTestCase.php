<?php

declare(strict_types=1);

namespace Orion\Testing;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Orion\Adapter\InMemory\InMemoryEngine;
use Orion\Orion;
use Orion\Registry\EngineRegistry;
use Orion\Storage\Column;
use Orion\Storage\Condition;
use Orion\Storage\DuplicateKey;
use Orion\IndexName;
use Orion\Outbox\Entry;
use Orion\Outbox\EntryKind;
use Orion\Outbox\Outbox;
use Orion\Lifecycle\Operation;
use Orion\Receipt\ReceiptStore;
use Orion\Receipt\SodiumSigner;
use Orion\Receipt\Verifier;
use Orion\Registry\IndexRegistration;
use Orion\Schema\IndexSchema;
use Orion\Storage\Schema;
use Orion\Storage\Storage;
use Orion\Storage\Table;
use Orion\Support\Canonical;
use Orion\Tenancy\Tenancy;
use Orion\Wiring\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_column;
use function array_map;
use function str_repeat;

/**
 * What every {@see Storage} adapter must do before it is an Orion storage adapter (docs/spec/revision.md §4):
 * the criteria grammar, ordering and paging, affected-row counts, identifier and value safety, and transactions
 * on the host's connection. The same-connection test is the gate: an entity row written through the host's own
 * connection and an outbox entry recorded through the adapter, in one adapter transaction, roll back together. An
 * adapter holding a second connection fails it.
 *
 * Extend it, return a fresh adapter from {@see createStorage()} with {@see tables()} installed and empty, and
 * implement {@see insertOnHostConnection()} and {@see inHostTransaction()} with the connection the adapter wraps
 * (raw PDO, Cycle's database), the way the host's own code would use it.
 */
abstract class StorageConformanceTestCase extends TestCase
{
    /** A host table standing in for the application's entity, installed beside Orion's own. */
    public const string ENTITY = 'orion_conformance_entity';

    private const string FAILURE = 'the entity write failed after the outbox row was recorded';

    private Storage $storage;

    abstract protected function createStorage(): Storage;

    /**
     * Writes a row the way the host application does, through its own connection and never through the adapter.
     *
     * @param array<string, mixed> $row
     */
    abstract protected function insertOnHostConnection(string $table, array $row): void;

    /**
     * Runs `$work` inside a transaction the host opens on its own connection (as its ORM would), committing when it
     * returns and rolling back and rethrowing when it throws.
     */
    abstract protected function inHostTransaction(callable $work): void;

    /**
     * Every table the adapter under test must install: Orion's and the conformance entity.
     *
     * @return list<Table>
     */
    public static function tables(): array
    {
        return [...Schema::tables(), self::entityTable()];
    }

    public static function entityTable(): Table
    {
        return Table::named(self::ENTITY, [
            Column::string('id', 36)->primary(),
            Column::string('name', 255)->nullable(),
            Column::int('position'),
            Column::datetime('created_at'),
        ]);
    }

    protected function setUp(): void
    {
        $this->storage = $this->createStorage();
    }

    public function testAnEntityRowAndAnOutboxEntryRollBackTogether(): void
    {
        $thrown = null;
        try {
            $this->storage->transaction(function (): void {
                $this->insertOnHostConnection(self::ENTITY, $this->entity('e1', 'Widget', 1));
                $this->outbox()->index(IndexName::of('products'), 'e1', ['title' => 'Widget']);
                $this->failTheWrite();
            });
        } catch (RuntimeException $exception) {
            $thrown = $exception;
        }

        self::assertNotNull($thrown, 'The transaction did not rethrow.');
        self::assertSame(self::FAILURE, $thrown->getMessage());
        self::assertSame(0, $this->storage->count(self::ENTITY, []));
        self::assertSame(0, $this->storage->count(Schema::OUTBOX, []));
        self::assertSame(0, $this->outbox()->head(IndexName::of('products')));
    }

    public function testAnEntityRowAndAnOutboxEntryCommitTogether(): void
    {
        $result = $this->storage->transaction(function (): string {
            $this->insertOnHostConnection(self::ENTITY, $this->entity('e1', 'Widget', 1));
            $this->outbox()->index(IndexName::of('products'), 'e1', ['title' => 'Widget']);

            return 'committed';
        });

        self::assertSame('committed', $result);
        self::assertSame(1, $this->storage->count(self::ENTITY, ['id' => 'e1']));
        self::assertSame(1, $this->storage->count(Schema::OUTBOX, ['document_id' => 'e1']));
    }

    public function testAnOutboxEntryRecordedInsideTheHostsTransactionRollsBackWithIt(): void
    {
        $thrown = null;
        try {
            $this->inHostTransaction(function (): void {
                $this->insertOnHostConnection(self::ENTITY, $this->entity('e1', 'Widget', 1));
                $this->outbox()->index(IndexName::of('products'), 'e1', ['title' => 'Widget']);
                $this->outbox()->index(IndexName::of('products'), 'e2', ['title' => 'Gadget']);
                $this->failTheWrite();
            });
        } catch (RuntimeException $exception) {
            $thrown = $exception;
        }

        self::assertNotNull($thrown, 'The host transaction did not rethrow.');
        self::assertSame(self::FAILURE, $thrown->getMessage(), 'The adapter failed inside the host transaction.');
        self::assertSame(0, $this->storage->count(self::ENTITY, []));
        self::assertSame(0, $this->storage->count(Schema::OUTBOX, []));
        self::assertSame(0, $this->outbox()->head(IndexName::of('products')), 'The offset was not rolled back with the entry.');
    }

    public function testAnOutboxEntryRecordedInsideTheHostsTransactionCommitsWithIt(): void
    {
        $this->inHostTransaction(function (): void {
            $this->insertOnHostConnection(self::ENTITY, $this->entity('e1', 'Widget', 1));
            $this->outbox()->index(IndexName::of('products'), 'e1', ['title' => 'Widget']);
        });

        self::assertSame(1, $this->storage->count(self::ENTITY, []));
        self::assertSame(1, $this->outbox()->head(IndexName::of('products')));
    }

    public function testAnOutboxEntryRoundTripsThroughTheDatabase(): void
    {
        $fields = ['title' => 'Lámpara "de pie"', 'price' => 10.0, 'stock' => 3, 'tags' => ['a', 'b'], 'dims' => ['h' => 1.5], 'note' => null];
        $outbox = $this->outbox();
        $outbox->index(IndexName::of('products'), 'p1', $fields);
        $outbox->index(IndexName::of('products'), 'p2', ['title' => 'Desk']);
        $outbox->index(IndexName::of('products'), 'p1', $fields);
        $outbox->delete(IndexName::of('products'), 'p2');

        $entries = $outbox->after(IndexName::of('products'), 0, 10);

        self::assertSame([3, 4], array_map(static fn(Entry $e): int => $e->offset, $entries));
        // A JSON column may reorder keys; the values and their types must survive it.
        self::assertSame(Canonical::sort($fields), Canonical::sort($entries[0]->fields ?? []));
        self::assertSame(EntryKind::Delete, $entries[1]->kind);
        self::assertSame('2026-10-26T12:00:00+00:00', $entries[0]->recordedAt->format(DATE_ATOM));
    }

    public function testANestedTransactionRollsBackToItsSavepoint(): void
    {
        $this->storage->transaction(function (): void {
            $this->storage->insert(self::ENTITY, $this->entity('outer', 'Kept', 1));
            try {
                $this->storage->transaction(function (): void {
                    $this->storage->insert(self::ENTITY, $this->entity('inner', 'Dropped', 2));

                    throw new RuntimeException('inner');
                });
            } catch (RuntimeException) {
            }
        });

        self::assertSame(['outer'], $this->ids($this->storage->findMany(self::ENTITY, [])));
    }

    public function testARegistryRowRoundTripsThroughTheDatabase(): void
    {
        $schema = IndexSchema::for('products')->text('title', language: 'en', boost: 2.0)->keyword('sku', filterable: true)->orgKey('org_id')->native('elasticsearch', ['settings' => ['index' => ['refresh_interval' => '1s', 'number_of_shards' => 1]]]);
        $registration = new IndexRegistration(IndexName::of('products_v1'), IndexName::of('products'), 'elasticsearch', 1, $schema, new DateTimeImmutable('2026-10-26 10:00:00'));

        $this->storage->insert(Schema::INDEX, $registration->toRow());
        $read = IndexRegistration::fromRow($this->storage->findOne(Schema::INDEX, ['name' => 'products_v1']) ?? []);

        // A JSON column may reorder keys and renumber; the declaration and its hash must survive it.
        self::assertSame($schema->hash(), $read->schema->hash());
        self::assertEquals(Canonical::sort($schema->toArray()), Canonical::sort($read->schema->toArray()));
        self::assertSame(1, $read->version);
        self::assertSame('2026-10-26T10:00:00+00:00', $read->createdAt->format(DATE_ATOM));
    }

    public function testIncrementAddsOneAndReturnsTheNewValue(): void
    {
        $this->storage->insert(Schema::COUNTER, ['name' => 'outbox:products', 'value' => 0]);
        $this->storage->insert(Schema::COUNTER, ['name' => 'outbox:orders', 'value' => 7]);

        self::assertSame(1, $this->storage->increment(Schema::COUNTER, ['name' => 'outbox:products'], 'value'));
        self::assertSame(2, $this->storage->increment(Schema::COUNTER, ['name' => 'outbox:products'], 'value'));
        self::assertEquals(2, $this->storage->findOne(Schema::COUNTER, ['name' => 'outbox:products'])['value'] ?? null);
        self::assertEquals(7, $this->storage->findOne(Schema::COUNTER, ['name' => 'outbox:orders'])['value'] ?? null);
    }

    public function testIncrementReturnsNullWhenNoRowMatches(): void
    {
        self::assertNull($this->storage->increment(Schema::COUNTER, ['name' => 'outbox:missing'], 'value'));
        self::assertSame(0, $this->storage->count(Schema::COUNTER, []));
    }

    public function testAnIncrementRollsBackWithItsTransaction(): void
    {
        $this->storage->insert(Schema::COUNTER, ['name' => 'outbox:products', 'value' => 3]);
        try {
            $this->inHostTransaction(function (): void {
                self::assertSame(4, $this->storage->increment(Schema::COUNTER, ['name' => 'outbox:products'], 'value'));
                $this->failTheWrite();
            });
        } catch (RuntimeException) {
        }

        self::assertSame(4, $this->storage->increment(Schema::COUNTER, ['name' => 'outbox:products'], 'value'));
    }

    public function testIncrementRefusesCriteriaMatchingSeveralRowsAndChangesNothing(): void
    {
        $this->seed();

        try {
            $this->storage->increment(self::ENTITY, ['position' => Condition::lt(3)], 'position');
            self::fail('An increment of several rows was accepted.');
        } catch (LogicException) {
            self::addToAssertionCount(1);
        }

        self::assertSame(['a', 'b'], $this->ids($this->storage->findMany(self::ENTITY, ['position' => [1, 2]], ['id' => 'asc'])));
    }

    public function testIncrementRefusesEmptyCriteria(): void
    {
        $this->storage->insert(Schema::COUNTER, ['name' => 'outbox:products', 'value' => 0]);

        $this->expectException(InvalidArgumentException::class);

        $this->storage->increment(Schema::COUNTER, [], 'value');
    }

    public function testAReceiptChainRoundTripsThroughTheDatabaseAndVerifies(): void
    {
        $signer = SodiumSigner::fromSeed(str_repeat("\x05", 32), 'conformance');
        $schema = IndexSchema::for('products')->text('title', language: 'en')->keyword('sku', filterable: true);
        $registration = new IndexRegistration(IndexName::of('products_v1'), IndexName::of('products'), 'elasticsearch', 1, $schema, new DateTimeImmutable('2026-10-26 10:00:00+00:00'));
        // Microseconds are part of the signed message, so the column must keep them; a whole second must survive too.
        foreach (['2026-10-26 12:00:00.123456+00:00', '2026-10-26 12:00:01+00:00'] as $at) {
            (new ReceiptStore($this->storage, $signer, new FixedClock(new DateTimeImmutable($at))))->append(Operation::Created, $registration, null, 0, 0, 3);
        }

        $store = new ReceiptStore($this->storage, null, new FixedClock());

        self::assertSame('2026-10-26T12:00:00.123456+00:00', $store->find(1)?->toArray()['recorded_at']);
        $verification = (new Verifier($store, $signer))->verify();
        self::assertSame([2, []], [$verification->receipts, $verification->breaks]);
    }

    public function testInsertingADuplicateKeyThrowsDuplicateKeyAndKeepsTheFirstRow(): void
    {
        $this->storage->insert(Schema::COUNTER, ['name' => 'outbox:products', 'value' => 1]);
        $row = ['index_name' => 'products', 'outbox_offset' => 1, 'document_id' => 'p1', 'operation' => 'delete', 'payload' => null, 'recorded_at' => new DateTimeImmutable('2026-10-26 12:00:00+00:00')];
        $this->storage->insert(Schema::OUTBOX, $row);

        $duplicates = [
            fn() => $this->storage->insert(Schema::COUNTER, ['name' => 'outbox:products', 'value' => 9]),
            fn() => $this->storage->insert(Schema::OUTBOX, [...$row, 'outbox_offset' => 2]),
        ];
        foreach ($duplicates as $duplicate) {
            try {
                $this->storage->transaction($duplicate);
                self::fail('A duplicate key was inserted.');
            } catch (DuplicateKey) {
                self::addToAssertionCount(1);
            }
        }

        self::assertEquals(1, $this->storage->findOne(Schema::COUNTER, ['name' => 'outbox:products'])['value'] ?? null);
        self::assertSame(1, $this->storage->count(Schema::OUTBOX, []));
    }

    public function testFindOneReturnsTheMatchingRowOrNull(): void
    {
        $this->seed();

        $row = $this->storage->findOne(self::ENTITY, ['id' => 'b']);

        self::assertNotNull($row);
        self::assertSame('Beta', $row['name']);
        self::assertEquals(2, $row['position']);
        self::assertNull($this->storage->findOne(self::ENTITY, ['id' => 'missing']));
    }

    public function testAScalarCriterionIsEquality(): void
    {
        $this->seed();

        self::assertSame(['b'], $this->ids($this->storage->findMany(self::ENTITY, ['name' => 'Beta'])));
    }

    public function testCriteriaAreAnded(): void
    {
        $this->seed();

        self::assertSame(['c'], $this->ids($this->storage->findMany(self::ENTITY, ['position' => 3, 'name' => 'Gamma'])));
        self::assertSame([], $this->storage->findMany(self::ENTITY, ['position' => 3, 'name' => 'Beta']));
    }

    public function testAListCriterionIsIn(): void
    {
        $this->seed();

        $rows = $this->storage->findMany(self::ENTITY, ['id' => ['a', 'c']], ['id' => 'asc']);

        self::assertSame(['a', 'c'], $this->ids($rows));
    }

    public function testAnEmptyListMatchesNothing(): void
    {
        $this->seed();

        self::assertSame([], $this->storage->findMany(self::ENTITY, ['id' => []]));
    }

    public function testANullCriterionIsIsNull(): void
    {
        $this->seed();

        self::assertSame(['d'], $this->ids($this->storage->findMany(self::ENTITY, ['name' => null])));
    }

    public function testConditionsCompare(): void
    {
        $this->seed();
        $order = ['position' => 'asc'];

        self::assertSame(['a', 'b'], $this->ids($this->storage->findMany(self::ENTITY, ['position' => Condition::lt(3)], $order)));
        self::assertSame(['a', 'b', 'c'], $this->ids($this->storage->findMany(self::ENTITY, ['position' => Condition::lte(3)], $order)));
        self::assertSame(['d'], $this->ids($this->storage->findMany(self::ENTITY, ['position' => Condition::gt(3)], $order)));
        self::assertSame(['c', 'd'], $this->ids($this->storage->findMany(self::ENTITY, ['position' => Condition::gte(3)], $order)));
        self::assertSame(['a', 'c', 'd'], $this->ids($this->storage->findMany(self::ENTITY, ['position' => Condition::ne(2)], $order)));
        self::assertSame(['a', 'b', 'c'], $this->ids($this->storage->findMany(self::ENTITY, ['name' => Condition::notNull()], $order)));
    }

    public function testComparingAgainstNullNeverMatches(): void
    {
        $this->seed();

        self::assertSame(['b', 'c'], $this->ids($this->storage->findMany(self::ENTITY, ['name' => Condition::ne('Alpha')], ['id' => 'asc'])));
        self::assertSame([], $this->storage->findMany(self::ENTITY, ['name' => Condition::lt(null)]));
    }

    public function testConditionsCompareDates(): void
    {
        $this->seed();

        $rows = $this->storage->findMany(self::ENTITY, ['created_at' => Condition::lt(new DateTimeImmutable('2026-01-03 00:00:00'))], ['id' => 'asc']);

        self::assertSame(['a', 'b'], $this->ids($rows));
    }

    public function testFindManyOrdersLimitsAndOffsets(): void
    {
        $this->seed();

        self::assertSame(['d', 'c', 'b', 'a'], $this->ids($this->storage->findMany(self::ENTITY, [], ['position' => 'desc'])));
        self::assertSame(['c', 'b'], $this->ids($this->storage->findMany(self::ENTITY, [], ['position' => 'desc'], 2, 1)));
    }

    public function testUpdateChangesTheMatchingRowsAndCountsThem(): void
    {
        $this->seed();

        self::assertSame(2, $this->storage->update(self::ENTITY, ['position' => Condition::lt(3)], ['name' => 'Low']));
        self::assertSame(['a', 'b'], $this->ids($this->storage->findMany(self::ENTITY, ['name' => 'Low'], ['id' => 'asc'])));
        self::assertSame(0, $this->storage->update(self::ENTITY, ['id' => 'missing'], ['name' => 'None']));
    }

    public function testUpdateCountsMatchedRowsEvenWhenNothingChanges(): void
    {
        $this->seed();

        self::assertSame(1, $this->storage->update(self::ENTITY, ['id' => 'a'], ['name' => 'Alpha']));
    }

    public function testDeleteRemovesTheMatchingRowsAndCountsThem(): void
    {
        $this->seed();

        self::assertSame(2, $this->storage->delete(self::ENTITY, ['id' => ['a', 'b']]));
        self::assertSame(2, $this->storage->count(self::ENTITY, []));
        self::assertSame(0, $this->storage->delete(self::ENTITY, ['id' => 'a']));
    }

    public function testCountHonoursCriteria(): void
    {
        $this->seed();

        self::assertSame(4, $this->storage->count(self::ENTITY, []));
        self::assertSame(2, $this->storage->count(self::ENTITY, ['position' => Condition::gte(3)]));
    }

    public function testFindManyOrdersByEveryColumnGiven(): void
    {
        $this->seed();
        $this->storage->insert(self::ENTITY, $this->entity('e', 'Beta', 9));

        $rows = $this->storage->findMany(self::ENTITY, ['name' => ['Beta', 'Alpha']], ['name' => 'asc', 'position' => 'desc']);

        self::assertSame(['a', 'e', 'b'], $this->ids($rows));
    }

    public function testALimitAloneStartsAtTheFirstRow(): void
    {
        $this->seed();

        self::assertSame(['a', 'b'], $this->ids($this->storage->findMany(self::ENTITY, [], ['id' => 'asc'], 2)));
    }

    public function testUpdateCanSetNull(): void
    {
        $this->seed();

        $this->storage->update(self::ENTITY, ['id' => 'a'], ['name' => null]);

        self::assertSame(['a', 'd'], $this->ids($this->storage->findMany(self::ENTITY, ['name' => null], ['id' => 'asc'])));
    }

    public function testUpdateAndDeleteRefuseEmptyCriteria(): void
    {
        $this->seed();

        foreach ([fn(): int => $this->storage->update(self::ENTITY, [], ['name' => 'All']), fn(): int => $this->storage->delete(self::ENTITY, [])] as $unbounded) {
            try {
                $unbounded();
                self::fail('An unbounded update or delete was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame(4, $this->storage->count(self::ENTITY, []));
        self::assertSame(0, $this->storage->count(self::ENTITY, ['name' => 'All']));
    }

    public function testValuesAreBoundNeverInterpolated(): void
    {
        $hostile = "x' OR '1'='1";
        $this->seed();
        $this->storage->insert(self::ENTITY, $this->entity('h', $hostile, 5));

        self::assertSame(['h'], $this->ids($this->storage->findMany(self::ENTITY, ['name' => $hostile])));
        self::assertSame([], $this->storage->findMany(self::ENTITY, ['name' => "' OR 1=1 --"]));
    }

    /**
     * @param callable(Storage): mixed $call
     */
    #[DataProvider('hostileIdentifiers')]
    public function testHostileIdentifiersAreRefused(callable $call): void
    {
        $this->expectException(InvalidArgumentException::class);

        $call($this->storage);
    }

    /**
     * @return iterable<string, array{callable(Storage): mixed}>
     */
    public static function hostileIdentifiers(): iterable
    {
        yield 'table' => [static fn(Storage $s): array => $s->findMany('orion_index; DROP TABLE orion_receipt', [])];
        yield 'criteria column' => [static fn(Storage $s): array => $s->findMany(self::ENTITY, ['id = id OR 1' => 1])];
        yield 'order column' => [static fn(Storage $s): array => $s->findMany(self::ENTITY, [], ['(SELECT 1)' => 'asc'])];
        /** @phpstan-ignore argument.type */
        yield 'order direction' => [static fn(Storage $s): array => $s->findMany(self::ENTITY, [], ['id' => 'asc; DROP TABLE x'])];
        yield 'row column' => [static fn(Storage $s) => $s->insert(self::ENTITY, ['id' => 'x', 'name) VALUES (1); --' => 'y'])];
        yield 'update column' => [static fn(Storage $s): int => $s->update(self::ENTITY, ['id' => 'a'], ["name\n" => 'y'])];
        yield 'increment column' => [static fn(Storage $s): ?int => $s->increment(self::ENTITY, ['id' => 'a'], 'position = 0, name')];
        yield 'increment table' => [static fn(Storage $s): ?int => $s->increment('orion_counter; --', ['name' => 'a'], 'value')];
        yield 'negative limit' => [static fn(Storage $s): array => $s->findMany(self::ENTITY, [], null, -1)];
        yield 'negative offset' => [static fn(Storage $s): array => $s->findMany(self::ENTITY, [], null, 1, -1)];
    }

    /**
     * Declared void, not never: whether the adapter rethrows is what the test checks.
     */
    private function failTheWrite(): void
    {
        throw new RuntimeException(self::FAILURE);
    }

    private function seed(): void
    {
        $this->storage->insert(self::ENTITY, $this->entity('a', 'Alpha', 1, '2026-01-01 00:00:00'));
        $this->storage->insert(self::ENTITY, $this->entity('b', 'Beta', 2, '2026-01-02 00:00:00'));
        $this->storage->insert(self::ENTITY, $this->entity('c', 'Gamma', 3, '2026-01-03 00:00:00'));
        $this->storage->insert(self::ENTITY, $this->entity('d', null, 4, '2026-01-04 00:00:00'));
    }

    /**
     * @return array<string, mixed>
     */
    private function entity(string $id, ?string $name, int $position, string $createdAt = '2026-01-01 00:00:00'): array
    {
        return ['id' => $id, 'name' => $name, 'position' => $position, 'created_at' => new DateTimeImmutable($createdAt)];
    }

    private function outbox(): Outbox
    {
        return Orion::create(new Config(storage: $this->storage, engines: new EngineRegistry(memory: new InMemoryEngine()), tenancy: Tenancy::single(), clock: new FixedClock(new DateTimeImmutable('2026-10-26 12:00:00+00:00'))))->graph()->outbox();
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<mixed>
     */
    private function ids(array $rows): array
    {
        return array_column($rows, 'id');
    }
}
