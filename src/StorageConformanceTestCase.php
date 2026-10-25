<?php

declare(strict_types=1);

namespace Orion\Testing;

use DateTimeImmutable;
use InvalidArgumentException;
use Orion\Storage\Column;
use Orion\Storage\Condition;
use Orion\Storage\Schema;
use Orion\Storage\Storage;
use Orion\Storage\Table;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_column;

/**
 * What every {@see Storage} adapter must do before it is an Orion storage adapter (docs/spec/revision.md §4):
 * the criteria grammar, ordering and paging, affected-row counts, identifier and value safety, and transactions
 * on the host's connection. The same-connection test is the gate: an entity row written through the host's own
 * connection and an outbox row written through the adapter, in one adapter transaction, roll back together. An
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

    public function testAnEntityRowAndAnOutboxRowRollBackTogether(): void
    {
        $thrown = null;
        try {
            $this->storage->transaction(function (): void {
                $this->insertOnHostConnection(self::ENTITY, $this->entity('e1', 'Widget', 1));
                $this->storage->insert(Schema::OUTBOX, $this->outbox('o1', 1, 'e1'));
                $this->failTheWrite();
            });
        } catch (RuntimeException $exception) {
            $thrown = $exception;
        }

        self::assertNotNull($thrown, 'The transaction did not rethrow.');
        self::assertSame(self::FAILURE, $thrown->getMessage());
        self::assertSame(0, $this->storage->count(self::ENTITY, []));
        self::assertSame(0, $this->storage->count(Schema::OUTBOX, []));
    }

    public function testAnEntityRowAndAnOutboxRowCommitTogether(): void
    {
        $result = $this->storage->transaction(function (): string {
            $this->insertOnHostConnection(self::ENTITY, $this->entity('e1', 'Widget', 1));
            $this->storage->insert(Schema::OUTBOX, $this->outbox('o1', 1, 'e1'));

            return 'committed';
        });

        self::assertSame('committed', $result);
        self::assertSame(1, $this->storage->count(self::ENTITY, ['id' => 'e1']));
        self::assertSame(1, $this->storage->count(Schema::OUTBOX, ['document_id' => 'e1']));
    }

    public function testAnOutboxRowRecordedInsideTheHostsTransactionRollsBackWithIt(): void
    {
        $thrown = null;
        try {
            $this->inHostTransaction(function (): void {
                $this->insertOnHostConnection(self::ENTITY, $this->entity('e1', 'Widget', 1));
                $this->storage->insert(Schema::OUTBOX, $this->outbox('o1', 1, 'e1'));
                $this->storage->transaction(fn() => $this->storage->insert(Schema::OUTBOX, $this->outbox('o2', 2, 'e1')));
                $this->failTheWrite();
            });
        } catch (RuntimeException $exception) {
            $thrown = $exception;
        }

        self::assertNotNull($thrown, 'The host transaction did not rethrow.');
        self::assertSame(self::FAILURE, $thrown->getMessage(), 'The adapter failed inside the host transaction.');
        self::assertSame(0, $this->storage->count(self::ENTITY, []));
        self::assertSame(0, $this->storage->count(Schema::OUTBOX, []));
    }

    public function testAnOutboxRowRecordedInsideTheHostsTransactionCommitsWithIt(): void
    {
        $this->inHostTransaction(function (): void {
            $this->insertOnHostConnection(self::ENTITY, $this->entity('e1', 'Widget', 1));
            $this->storage->transaction(fn() => $this->storage->insert(Schema::OUTBOX, $this->outbox('o1', 1, 'e1')));
        });

        self::assertSame(1, $this->storage->count(self::ENTITY, []));
        self::assertSame(1, $this->storage->count(Schema::OUTBOX, []));
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

    /**
     * @return array<string, mixed>
     */
    private function outbox(string $id, int $offset, string $documentId): array
    {
        return [
            'id' => $id,
            'outbox_offset' => $offset,
            'index_name' => 'products',
            'document_id' => $documentId,
            'operation' => 'index',
            'payload' => '{"title":"Widget"}',
            'recorded_at' => new DateTimeImmutable('2026-01-01 00:00:00'),
        ];
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
