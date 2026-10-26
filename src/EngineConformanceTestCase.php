<?php

declare(strict_types=1);

namespace Orion\Testing;

use InvalidArgumentException;
use Orion\Adapter\Capability;
use Orion\Adapter\RequiresReindex;
use Orion\Adapter\SchemaAdapter;
use Orion\Adapter\SearchAdapter;
use Orion\Adapter\UnsupportedClause;
use Orion\Adapter\WriteAdapter;
use Orion\IndexName;
use Orion\Query\Direction;
use Orion\Query\Filter;
use Orion\Query\Fusion;
use Orion\Query\Hit;
use Orion\Query\Query;
use Orion\Query\ResultSet;
use Orion\Schema\Field;
use Orion\Schema\IndexSchema;
use Orion\Schema\MigrationPlan;
use Orion\Schema\VectorMetric;
use Orion\Write\Document;
use Orion\Write\Tombstone;
use Orion\Write\WriteOutcome;
use Orion\Write\WriteStatus;
use PHPUnit\Framework\TestCase;

use function array_map;
use function bin2hex;
use function random_bytes;
use function sort;
use function stripos;

/**
 * What every engine adapter must do before it ships (v1 §18.1): schema create, inspect, plan and in-place apply
 * without false drift; version-stamped writes and deletes that converge; the neutral query AST with portable
 * semantics; the scoped escape hatch; an honest {@see UnsupportedClause} for every capability it reports false; and
 * {@see RequiresReindex}, never a silent partial change, for a plan it cannot apply in place.
 *
 * Only portable behaviour is asserted. Without an explicit sort, hits are compared as sets: ranking is the engine's
 * and is not deterministic (v1 §17). Every test runs on a fresh index, dropped afterwards.
 */
abstract class EngineConformanceTestCase extends TestCase
{
    private const int AWAIT_SECONDS = 30;

    private SearchAdapter&SchemaAdapter&WriteAdapter $engine;
    private IndexName $index;

    abstract protected function createEngine(): SearchAdapter&SchemaAdapter&WriteAdapter;

    /**
     * A native query matching every document, in the shape the adapter's `raw()` documents.
     */
    abstract protected function nativeMatchAll(): callable;

    /**
     * Other native bodies the adapter's `raw()` accepts, by name, each able to match every seeded document: the
     * scope must hold for every one of them.
     *
     * @return array<string, callable>
     */
    protected function nativeShapes(): array
    {
        return [];
    }

    /**
     * Native bodies that would read outside a scope (index-wide aggregations, suggesters): `raw()` must refuse
     * them with InvalidArgumentException when scope filters are given.
     *
     * @return array<string, callable>
     */
    protected function nativeScopeEscapes(): array
    {
        return [];
    }

    protected function setUp(): void
    {
        $this->engine = $this->createEngine();
        $this->index = IndexName::of('orion_conformance_' . bin2hex(random_bytes(6)));
    }

    protected function tearDown(): void
    {
        // A subclass may skip in setUp() before the engine exists.
        if (isset($this->engine)) {
            $this->engine->drop($this->index);
        }
    }

    // --- schema ---------------------------------------------------------------------------------------------

    public function testInspectingAMissingIndexReturnsNull(): void
    {
        self::assertNull($this->engine->inspect($this->index));
    }

    public function testEnsureCreatesAnIndexWithoutDrift(): void
    {
        $schema = $this->schema();
        $this->engine->ensure($schema);

        self::assertNotNull($this->engine->inspect($this->index));
        self::assertTrue(MigrationPlan::between($schema, $this->engine->inspect($this->index))->isEmpty());
        self::assertTrue($this->engine->plan($schema)->isEmpty());
    }

    public function testEnsureIsIdempotent(): void
    {
        $this->engine->ensure($this->schema());
        $this->engine->ensure($this->schema());

        self::assertTrue($this->engine->plan($this->schema())->isEmpty());
    }

    public function testAnAdditivePlanAppliesInPlace(): void
    {
        $this->seed();
        $desired = $this->schema()->keyword('color', filterable: true);

        $plan = $this->engine->plan($desired);
        self::assertSame(['color'], array_map(static fn($change): string => $change->path, $plan->additive()));
        $this->engine->apply($plan)->await(self::AWAIT_SECONDS);

        self::assertTrue($this->engine->plan($desired)->isEmpty());
        self::assertSame(['1', '2', '4'], $this->ids($this->search($this->query()->match('title', 'running'))));
    }

    public function testEnsureAcceptsEveryNeutralFieldShapeWithoutDrift(): void
    {
        $schema = IndexSchema::for($this->index->value)
            ->text('plain')
            ->text('folded', folding: true)
            ->text('stemmed', language: 'en')
            ->text('stemmed_folded', language: 'en', folding: true)
            ->keyword('code', filterable: true, sortable: true, facetable: true)
            ->geo('at', filterable: true, sortable: true)
            ->object('address', Field::keyword('city', filterable: true), Field::integer('floor', sortable: true));
        if ($this->engine->supports(Capability::NestedDocuments)) {
            $schema = $schema->nested('variants', Field::keyword('size', filterable: true));
        }

        $this->engine->ensure($schema);

        self::assertTrue($this->engine->plan($schema)->isEmpty());
    }

    public function testAnAdditivePlanEitherAppliesWithoutDriftOrIsRefused(): void
    {
        $this->engine->ensure($this->schema());
        $desired = $this->schema()->text('summary', language: 'en', folding: true)->with(Field::object('meta', Field::keyword('origin')));

        try {
            $this->engine->apply($this->engine->plan($desired))->await(self::AWAIT_SECONDS);
            self::assertTrue($this->engine->plan($desired)->isEmpty());
        } catch (RequiresReindex) {
            self::assertFalse($this->engine->plan($desired)->isEmpty(), 'A refused plan must leave the index unchanged.');
        }
    }

    public function testABreakingPlanIsNeverAppliedInPlace(): void
    {
        $this->engine->ensure($this->schema());
        $plan = $this->engine->plan(IndexSchema::for($this->index->value)->text('sku'));

        $this->expectException(RequiresReindex::class);

        $this->engine->apply($plan);
    }

    public function testAnAliasPointsAtOnePhysicalIndexAtATimeOrIsRefused(): void
    {
        $alias = $this->index;
        $v1 = IndexName::of($alias->value . '_v1');
        $v2 = IndexName::of($alias->value . '_v2');
        if (!$this->engine->supports(Capability::NativeAliases)) {
            $this->expectException(UnsupportedClause::class);

            $this->engine->alias($alias, $v1);
        }

        try {
            $this->engine->ensure($this->schema()->named($v1));
            $this->engine->index($v1, $this->corpus()[0])->await(self::AWAIT_SECONDS);
            $this->engine->flush($v1, await: true);
            $this->engine->alias($alias, $v1);
            self::assertSame(['1'], $this->ids($this->search($this->query())), 'Searching the alias reaches the physical index.');
            self::assertTrue(MigrationPlan::between($this->schema(), $this->engine->inspect($alias))->isEmpty(), 'Inspecting the alias reads the physical index.');

            $this->engine->ensure($this->schema()->keyword('color')->named($v2));
            $this->engine->index($v2, $this->corpus()[1])->await(self::AWAIT_SECONDS);
            $this->engine->flush($v2, await: true);
            $this->engine->alias($alias, $v2);
            self::assertSame(['2'], $this->ids($this->search($this->query())), 'The swap moves the alias off the old index.');
            self::assertSame(['color'], array_map(static fn($change): string => $change->path, MigrationPlan::between($this->schema()->keyword('color'), $this->engine->inspect($v1))->additive()), 'The old index is left as it was.');
            self::assertTrue(MigrationPlan::between($this->schema()->keyword('color'), $this->engine->inspect($alias))->isEmpty());
        } finally {
            $this->engine->drop($v1);
            $this->engine->drop($v2);
        }
    }

    public function testDropRemovesTheIndexAndToleratesAMissingOne(): void
    {
        $this->engine->ensure($this->schema());
        $this->engine->drop($this->index);
        $this->engine->drop($this->index);

        self::assertNull($this->engine->inspect($this->index));
    }

    // --- writes -----------------------------------------------------------------------------------------------

    public function testIndexingReportsAnOutcomePerDocument(): void
    {
        $this->engine->ensure($this->schema());

        $result = $this->engine->index($this->index, ...$this->corpus())->await(self::AWAIT_SECONDS);

        self::assertTrue($result->isComplete());
        self::assertSame(['1', '2', '3', '4', '5'], array_map(static fn(WriteOutcome $o): string => $o->id, $result->outcomes));
        self::assertSame([], $result->failures());
    }

    public function testAnUndeclaredFieldFailsThatDocumentOnly(): void
    {
        $this->engine->ensure($this->schema());

        $result = $this->engine->index(
            $this->index,
            new Document('ok', ['title' => 'Fine', 'sku' => 'Z9'], 1),
            new Document('bad', ['title' => 'Bad', 'undeclared' => 'x'], 1),
        )->await(self::AWAIT_SECONDS);
        $this->engine->flush($this->index, await: true);

        self::assertSame(['bad'], array_map(static fn(WriteOutcome $o): string => $o->id, $result->failures()));
        self::assertSame(['ok'], $this->ids($this->search($this->query())));
    }

    public function testWritingToAMissingIndexFailsInsteadOfCreatingIt(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->engine->index($this->index, ...$this->corpus());
    }

    public function testDeletingFromAMissingIndexFails(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->engine->delete($this->index, new Tombstone('1', 1));
    }

    public function testAValueOfTheWrongTypeFailsThatDocumentOnly(): void
    {
        $this->engine->ensure($this->schema());

        $result = $this->engine->index(
            $this->index,
            new Document('ok', ['title' => 'Fine', 'stock' => 3, 'embedding' => [1.0, 0.0, 0.0]], 1),
            new Document('text_in_integer', ['title' => 'Bad', 'stock' => 'many'], 1),
            new Document('short_vector', ['title' => 'Bad', 'embedding' => [1.0, 0.0]], 1),
        )->await(self::AWAIT_SECONDS);

        self::assertSame(['text_in_integer', 'short_vector'], array_map(static fn(WriteOutcome $o): string => $o->id, $result->failures()));
    }

    public function testDeletesRemoveDocuments(): void
    {
        $this->seed();

        $result = $this->engine->delete($this->index, new Tombstone('3', 10))->await(self::AWAIT_SECONDS);
        $this->engine->flush($this->index, await: true);

        self::assertSame([], $result->failures());
        self::assertSame([], $this->ids($this->search($this->query()->match('title', 'boot'))));
    }

    public function testVersionStampedWritesConvergeWhateverTheArrivalOrder(): void
    {
        $this->seed();
        if (!$this->engine->supports(Capability::OptimisticConcurrency)) {
            $this->engine->index($this->index, $this->document('1', 'Latest write wins', 0))->await(self::AWAIT_SECONDS);
            $this->engine->flush($this->index, await: true);
            self::assertSame('Latest write wins', $this->source('1')['title']);

            return;
        }

        self::assertSame(WriteStatus::Applied, $this->write($this->document('1', 'Version ten', 10)));
        self::assertArrayNotHasKey('price', $this->source('1'), 'A write replaces the document; it does not merge into it.');
        self::assertSame(WriteStatus::Stale, $this->write($this->document('1', 'Version seven', 7)));
        self::assertSame(WriteStatus::Stale, $this->write($this->document('1', 'Version ten again', 10)));
        self::assertSame('Version ten', $this->source('1')['title']);

        self::assertSame(WriteStatus::Stale, $this->remove(new Tombstone('1', 8)));
        self::assertSame('Version ten', $this->source('1')['title']);

        self::assertSame(WriteStatus::Applied, $this->remove(new Tombstone('1', 11)));
        self::assertSame(WriteStatus::Stale, $this->write($this->document('1', 'Replayed after the delete', 10)));
        self::assertSame([], $this->ids($this->search($this->query()->filter(Filter::term('sku', 'A1')))));

        // A delete that arrives before an older write of a document never indexed still wins.
        self::assertSame(WriteStatus::Applied, $this->remove(new Tombstone('9', 5)));
        self::assertSame(WriteStatus::Stale, $this->write($this->document('9', 'Older than its delete', 3)));
    }

    // --- lexical clauses ---------------------------------------------------------------------------------------

    public function testMatchFindsAnalysedTermsWhateverTheirCase(): void
    {
        $this->seed();

        self::assertSame(['1', '2', '4'], $this->ids($this->search($this->query()->match('title', 'running'))));
        self::assertSame(['1', '2', '4'], $this->ids($this->search($this->query()->match('title', 'RUNNING'))));
        self::assertSame(3, $this->search($this->query()->match('title', 'running'))->total);
    }

    public function testTermMatchesAnExactValue(): void
    {
        $this->seed();

        self::assertSame(['3'], $this->ids($this->search($this->query()->term('sku', 'C3'))));
        self::assertSame(['1', '3'], $this->ids($this->search($this->query()->term('brand', 'acme'))));
        self::assertSame([], $this->ids($this->search($this->query()->term('brand', 'ACME'))), 'term is exact: it does not fold case.');
    }

    public function testPrefixMatchesTheStartOfATerm(): void
    {
        $this->seed();

        self::assertSame(['3'], $this->ids($this->search($this->query()->prefix('title', 'hik'))));
        self::assertSame(['1'], $this->ids($this->search($this->query()->prefix('sku', 'A'))));
    }

    public function testPhraseNeedsTheTermsAdjacentAndInOrder(): void
    {
        $this->seed();

        self::assertSame(['1', '2'], $this->ids($this->search($this->query()->phrase('title', 'running shoe'))));
        self::assertSame([], $this->ids($this->search($this->query()->phrase('title', 'shoe running'))));
    }

    public function testClausesAreConjunctive(): void
    {
        $this->seed();

        self::assertSame(['2'], $this->ids($this->search($this->query()->match('title', 'running')->term('brand', 'globex'))));
    }

    // --- filters ------------------------------------------------------------------------------------------------

    public function testTermAndTermsFilters(): void
    {
        $this->seed();

        self::assertSame(['1', '3'], $this->ids($this->search($this->query()->filter(Filter::term('brand', 'acme')))));
        self::assertSame(['2'], $this->ids($this->search($this->query()->filter(Filter::term('in_stock', false)))));
        self::assertSame(['1', '3', '4'], $this->ids($this->search($this->query()->filter(Filter::terms('brand', ['acme', 'initech'])))));
    }

    public function testRangeFiltersOnNumbersAndDates(): void
    {
        $this->seed();

        self::assertSame(['1', '2', '5'], $this->ids($this->search($this->query()->filter(Filter::range('price', gte: 90, lt: 150)))));
        self::assertSame(['4'], $this->ids($this->search($this->query()->filter(Filter::range('stock', gt: 1, lte: 40))->filter(Filter::range('price', lt: 100)))));
        self::assertSame(['3', '4', '5'], $this->ids($this->search($this->query()->filter(Filter::range('released', gte: '2026-03-01')))));
    }

    public function testExistsFilter(): void
    {
        $this->seed();

        self::assertSame(['1', '2', '3', '4'], $this->ids($this->search($this->query()->filter(Filter::exists('stock')))));
    }

    public function testBoolFilters(): void
    {
        $this->seed();

        $mustAndNot = Filter::bool(must: [Filter::term('in_stock', true)], mustNot: [Filter::term('brand', 'acme')]);
        self::assertSame(['4', '5'], $this->ids($this->search($this->query()->filter($mustAndNot))));

        $should = Filter::bool(should: [Filter::term('brand', 'acme'), Filter::term('brand', 'globex')]);
        self::assertSame(['1', '2', '3', '5'], $this->ids($this->search($this->query()->filter($should))));

        $mustAndShould = Filter::bool(must: [Filter::range('price', lt: 100)], should: [Filter::term('brand', 'globex'), Filter::term('brand', 'initech')]);
        self::assertSame(['2', '4', '5'], $this->ids($this->search($this->query()->filter($mustAndShould))));
    }

    // --- sorting, paging, envelope -----------------------------------------------------------------------------

    public function testSortingByAField(): void
    {
        $this->seed();

        self::assertSame(['4', '2', '5', '1', '3'], $this->orderedIds($this->search($this->query()->sort('price', Direction::Asc))));
        self::assertSame(['3', '1', '5', '2', '4'], $this->orderedIds($this->search($this->query()->sort('price', Direction::Desc))));
    }

    public function testPaginationSlicesTheSortedResults(): void
    {
        $this->seed();

        $page = $this->search($this->query()->sort('price', Direction::Asc)->paginate(page: 2, size: 2));

        self::assertSame(['5', '1'], $this->orderedIds($page));
        self::assertSame(5, $page->total);
        self::assertFalse($page->totalIsEstimate);
    }

    public function testHitsCarryTheirSource(): void
    {
        $this->seed();

        $hits = $this->search($this->query()->term('sku', 'C3'))->hits;

        self::assertCount(1, $hits);
        self::assertSame('Leather hiking boot', $hits[0]->source['title']);
        self::assertSame('acme', $hits[0]->source['brand']);
    }

    public function testFacetsCountValuesWithinTheFilteredHits(): void
    {
        $this->seed();
        $query = $this->query()->filter(Filter::term('in_stock', true))->facet('brand');
        if (!$this->engine->supports(Capability::Facets)) {
            $this->expectException(UnsupportedClause::class);
        }

        $facets = $this->search($query)->facets;

        self::assertEquals(['acme' => 2, 'globex' => 1, 'initech' => 1], $facets['brand']);
    }

    public function testHighlightsMarkTheMatchedField(): void
    {
        $this->seed();
        $query = $this->query()->match('title', 'hiking')->highlight('title');
        if (!$this->engine->supports(Capability::Highlighting)) {
            $this->expectException(UnsupportedClause::class);
        }

        $hits = $this->search($query)->hits;

        self::assertCount(1, $hits);
        self::assertNotEmpty($hits[0]->highlights['title'] ?? []);
        self::assertNotFalse(stripos($hits[0]->highlights['title'][0], 'hiking'));
    }

    public function testHighlightsAreHtmlSafe(): void
    {
        $this->engine->ensure($this->schema());
        $this->engine->index($this->index, new Document('x', ['title' => 'Hiking <img src=x onerror=alert(1)> & more', 'sku' => 'X1'], 1))->await(self::AWAIT_SECONDS);
        $this->engine->flush($this->index, await: true);
        $query = $this->query()->match('title', 'hiking')->highlight('title');
        if (!$this->engine->supports(Capability::Highlighting)) {
            $this->expectException(UnsupportedClause::class);
        }

        $fragment = $this->search($query)->hits[0]->highlights['title'][0] ?? '';

        self::assertStringContainsString('<em>Hiking</em>', $fragment);
        self::assertStringNotContainsString('<img', $fragment);
        self::assertStringContainsString('&lt;img', $fragment);
    }

    // --- vectors and hybrid -------------------------------------------------------------------------------------

    public function testVectorSearchReturnsTheNearestNeighbours(): void
    {
        $this->seed();
        $query = $this->query()->vector('embedding', [1.0, 0.0, 0.0], k: 2);
        if (!$this->engine->supports(Capability::Vectors)) {
            $this->expectException(UnsupportedClause::class);
        }

        self::assertSame(['1', '2'], $this->orderedIds($this->search($query)));
    }

    public function testVectorSearchHonoursFilters(): void
    {
        $this->seed();
        $query = $this->query()->vector('embedding', [1.0, 0.0, 0.0], k: 2)->filter(Filter::term('brand', 'acme'));
        if (!$this->engine->supports(Capability::Vectors)) {
            $this->expectException(UnsupportedClause::class);
        }

        self::assertSame(['1', '3'], $this->orderedIds($this->search($query)));
    }

    public function testHybridRetrievalWithoutNativeFusionIsLeftToCore(): void
    {
        if ($this->engine->supports(Capability::HybridFusion)) {
            self::markTestSkipped('The engine fuses natively; core fusion is not involved.');
        }
        $this->seed();

        $this->expectException(UnsupportedClause::class);

        $this->search($this->query()->match('title', 'running')->vector('embedding', [1.0, 0.0, 0.0], k: 2)->fuse(Fusion::reciprocalRank()));
    }

    // --- escape hatch -------------------------------------------------------------------------------------------

    public function testTheEscapeHatchReturnsTheSameEnvelope(): void
    {
        $this->seed();

        self::assertSame(['1', '2', '3', '4', '5'], $this->ids($this->engine->raw($this->index, $this->nativeMatchAll())));
    }

    public function testTheEscapeHatchIsScoped(): void
    {
        $this->seed();

        $results = $this->engine->raw($this->index, $this->nativeMatchAll(), Filter::term('brand', 'acme'), Filter::term('in_stock', true));

        self::assertSame(['1', '3'], $this->ids($results));
    }

    public function testTheScopeHoldsForEveryNativeShape(): void
    {
        $this->seed();
        foreach ($this->nativeShapes() as $name => $native) {
            $results = $this->engine->raw($this->index, $native, Filter::term('brand', 'acme'));

            self::assertNotSame([], $results->hits, "The native shape '$name' matched nothing.");
            foreach ($results->hits as $hit) {
                self::assertSame('acme', $hit->source['brand'] ?? null, "The native shape '$name' escaped its scope with document {$hit->id}.");
            }
        }
        $this->addToAssertionCount(1);
    }

    public function testNativeShapesThatReadOutsideTheScopeAreRefused(): void
    {
        $this->seed();
        foreach ($this->nativeScopeEscapes() as $name => $native) {
            try {
                $this->engine->raw($this->index, $native, Filter::term('brand', 'acme'));
                self::fail("The native shape '$name' was run under a scope it can read past.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->addToAssertionCount(1);
    }

    // --- fixtures -----------------------------------------------------------------------------------------------

    protected function schema(): IndexSchema
    {
        return IndexSchema::for($this->index->value)
            ->text('title', language: 'en')
            ->keyword('sku', filterable: true, sortable: true)
            ->keyword('brand', filterable: true, facetable: true)
            ->float('price', filterable: true, sortable: true)
            ->integer('stock', filterable: true, sortable: true)
            ->boolean('in_stock', filterable: true)
            ->date('released', filterable: true, sortable: true)
            ->with(Field::vector('embedding', dims: 3, metric: VectorMetric::Cosine));
    }

    /**
     * @return list<Document>
     */
    protected function corpus(): array
    {
        $doc = static fn(string $id, string $title, string $sku, string $brand, float $price, ?int $stock, bool $inStock, string $released, array $embedding): Document => new Document(
            $id,
            [
                'title' => $title,
                'sku' => $sku,
                'brand' => $brand,
                'price' => $price,
                ...($stock === null ? [] : ['stock' => $stock]),
                'in_stock' => $inStock,
                'released' => $released,
                'embedding' => $embedding,
            ],
            1,
        );

        return [
            $doc('1', 'Trail running shoe', 'A1', 'acme', 120.0, 5, true, '2026-01-10', [1.0, 0.0, 0.0]),
            $doc('2', 'Road running shoe', 'B2', 'globex', 90.0, 0, false, '2026-02-10', [0.9, 0.1, 0.0]),
            $doc('3', 'Leather hiking boot', 'C3', 'acme', 150.0, 2, true, '2026-03-10', [0.0, 1.0, 0.0]),
            $doc('4', 'Running socks', 'D4', 'initech', 15.0, 40, true, '2026-04-10', [0.8, 0.0, 0.2]),
            $doc('5', 'Rain jacket', 'E5', 'globex', 99.0, null, true, '2026-05-10', [0.0, 0.0, 1.0]),
        ];
    }

    private function seed(): void
    {
        $this->engine->ensure($this->schema());
        $result = $this->engine->index($this->index, ...$this->corpus())->await(self::AWAIT_SECONDS);
        self::assertSame([], $result->failures(), 'The corpus did not index cleanly.');
        $this->engine->flush($this->index, await: true);
    }

    private function query(): Query
    {
        return Query::on($this->index->value);
    }

    private function search(Query $query): ResultSet
    {
        return $this->engine->search($this->index, $query);
    }

    private function document(string $id, string $title, int $version): Document
    {
        return new Document($id, ['title' => $title, 'sku' => 'A1', 'brand' => 'acme'], $version);
    }

    private function write(Document $document): WriteStatus
    {
        $outcome = $this->engine->index($this->index, $document)->await(self::AWAIT_SECONDS)->outcomes[0];
        $this->engine->flush($this->index, await: true);

        return $outcome->status;
    }

    private function remove(Tombstone $tombstone): WriteStatus
    {
        $outcome = $this->engine->delete($this->index, $tombstone)->await(self::AWAIT_SECONDS)->outcomes[0];
        $this->engine->flush($this->index, await: true);

        return $outcome->status;
    }

    /**
     * @return array<string, mixed>
     */
    private function source(string $id): array
    {
        foreach ($this->search($this->query()->paginate(size: 100))->hits as $hit) {
            if ($hit->id === $id) {
                return $hit->source;
            }
        }

        self::fail("Document $id is not in the index.");
    }

    /**
     * Hit ids as a sorted set, for queries whose ranking is the engine's.
     *
     * @return list<string>
     */
    private function ids(ResultSet $results): array
    {
        $ids = $this->orderedIds($results);
        sort($ids);

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function orderedIds(ResultSet $results): array
    {
        return array_map(static fn(Hit $hit): string => $hit->id, $results->hits);
    }
}
