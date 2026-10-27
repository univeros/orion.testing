<?php

declare(strict_types=1);

namespace Orion\Testing\Equivalence;

use Orion\Adapter\InMemory\InMemoryEngine;
use Orion\Adapter\SchemaAdapter;
use Orion\Adapter\SearchAdapter;
use Orion\Adapter\WriteAdapter;
use Orion\IndexName;
use Orion\Orion;
use Orion\Query\Hit;
use Orion\Query\Query;
use Orion\Query\ResultSet;
use Orion\Query\Sort;
use Orion\Registry\EngineRegistry;
use Orion\Tenancy\Tenancy;
use Orion\Testing\InMemoryStorage;
use Orion\Wiring\Config;
use Orion\Wiring\Graph;
use PHPUnit\Framework\TestCase;

use function abs;
use function array_diff;
use function array_map;
use function array_values;
use function count;
use function bin2hex;
use function getenv;
use function json_encode;
use function ksort;
use function random_bytes;
use function sort;
use function sprintf;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

/**
 * Property-based query equivalence (v1 §18.2, the WP8 acceptance gate): over a generated corpus, every generated
 * neutral query answers the same ids, total and facet counts on the engine under test as on the in-memory engine,
 * whose semantics the conformance case defines; where the query orders its hits totally, the order too. Both run
 * through the searcher, so the schema binding and core fusion are part of what is compared. A query with a vector
 * clause is held to the k-nearest-neighbour promise real engines make, an approximate one (Elasticsearch quantises
 * vectors to int8 by default, Solr's HNSW graph is approximate): as many hits as the oracle's, every one among the
 * oracle's `k + SLACK` nearest. `ORION_SEED` picks the seed; a failure reports the seed and the query's declarative
 * form, so it replays.
 */
abstract class QueryEquivalenceTestCase extends TestCase
{
    public const string SEED = 'ORION_SEED';
    public const int DEFAULT_SEED = 20261026;
    /** Fewer than {@see QueryGenerator::WHOLE_PAGE}, so an unordered query's matches all fit one page. */
    protected const int DOCUMENTS = 40;
    protected const int QUERIES = 120;
    /** How far past k an approximate nearest-neighbour search may reach for its k hits. */
    protected const int SLACK = 3;
    private const string ORACLE = 'memory';
    private const string SUBJECT = 'subject';

    private SearchAdapter&SchemaAdapter&WriteAdapter $subject;
    private IndexName $index;
    private Graph $oracle;
    private Graph $graph;

    abstract protected function createEngine(): SearchAdapter&SchemaAdapter&WriteAdapter;

    protected function setUp(): void
    {
        $this->subject = $this->createEngine();
        $this->index = IndexName::of('orion_equivalence_' . bin2hex(random_bytes(6)));
        $this->oracle = self::graph(self::ORACLE, new InMemoryEngine());
        $this->graph = self::graph(self::SUBJECT, $this->subject);
        $schema = QueryGenerator::schema($this->index->value);
        $this->oracle->planner(self::ORACLE)->apply($schema);
        $this->graph->planner(self::SUBJECT)->apply($schema);
    }

    protected function tearDown(): void
    {
        if (isset($this->graph)) {
            foreach ($this->graph->registry()->versions($this->index) as $registration) {
                $this->subject->drop($registration->physical);
            }
        }
    }

    public function testEveryGeneratedQueryAnswersAsTheOracleDoes(): void
    {
        $seed = (int) (getenv(self::SEED) ?: self::DEFAULT_SEED);
        $generator = new QueryGenerator(new Seeded($seed));
        foreach ($generator->corpus(static::DOCUMENTS) as $document) {
            $this->oracle->outbox()->index($this->index, $document->id, $document->fields);
            $this->graph->outbox()->index($this->index, $document->id, $document->fields);
        }
        $this->oracle->drain()->run($this->index);
        $drained = $this->graph->drain()->run($this->index);
        self::assertSame(0, $drained->failed, sprintf('seed %d: the corpus did not index cleanly on the engine under test.', $seed));
        $this->subject->flush($this->graph->aliases()->target($this->index), await: true);

        for ($i = 1; $i <= static::QUERIES; ++$i) {
            $query = $generator->query($this->index->value);
            $label = sprintf("seed %d, query %d:\n%s", $seed, $i, json_encode($query->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            $expected = $this->oracle->searcher()->search($query);
            $actual = $this->graph->searcher()->search($query);
            if ($query->vector !== null) {
                $this->assertNearest($query, $expected, $actual, $label);
                continue;
            }

            self::assertSame(self::ids($expected, $query), self::ids($actual, $query), $label);
            self::assertSame($expected->total, $actual->total, $label . "\n(total)");
            self::assertSame(self::facets($expected), self::facets($actual), $label . "\n(facets)");
        }
    }

    /**
     * The approximate promise: as many hits as the exact oracle finds, each among the oracle's `k + SLACK` nearest
     * (and, under fusion, the lexical leg's matches); the lexical legs are exact, so the union can differ only there.
     * Under fusion the count itself may differ by up to `SLACK`: an approximate neighbour may coincide with a lexical
     * match where the exact one did not, or the other way round, and the union shrinks or grows by it.
     */
    private function assertNearest(Query $query, ResultSet $expected, ResultSet $actual, string $label): void
    {
        $vector = $query->vector;
        self::assertNotNull($vector);
        $wider = $this->oracle->searcher()->search($query->vector($vector->field, $vector->vector, $vector->k + static::SLACK));
        $allowed = self::ids($wider, $query);
        $beyond = array_diff(self::ids($actual, $query), $allowed);

        self::assertSame([], array_values($beyond), $label . sprintf("\n(hits beyond the oracle's %d nearest)", $vector->k + static::SLACK));
        if ($query->clauses === []) {
            self::assertSame(count($expected->hits), count($actual->hits), $label . "\n(hit count)");
        } else {
            self::assertLessThanOrEqual(static::SLACK, abs(count($expected->hits) - count($actual->hits)), $label . "\n(hit count under fusion)");
        }
    }

    /**
     * @return list<string> in order when the query orders totally, sorted otherwise
     */
    private static function ids(ResultSet $results, Query $query): array
    {
        $ids = array_map(static fn(Hit $hit): string => $hit->id, $results->hits);
        if (!self::ordered($query)) {
            sort($ids);
        }

        return $ids;
    }

    /**
     * Whether the query's hit order is contractual: a total order on fields, which every engine resolves alike.
     */
    private static function ordered(Query $query): bool
    {
        return $query->sorts !== [] && $query->sorts[0]->field !== Sort::SCORE;
    }

    /**
     * @return array<string, array<string, int>>
     */
    private static function facets(ResultSet $results): array
    {
        $facets = [];
        foreach ($results->facets as $field => $buckets) {
            ksort($buckets);
            $facets[$field] = $buckets;
        }
        ksort($facets);

        return $facets;
    }

    private static function graph(string $name, SearchAdapter&SchemaAdapter&WriteAdapter $engine): Graph
    {
        return Orion::create(new Config(
            storage: new InMemoryStorage(),
            engines: new EngineRegistry(...[$name => $engine]),
            tenancy: Tenancy::single(),
        ))->graph();
    }
}
