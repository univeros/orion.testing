<?php

declare(strict_types=1);

namespace Orion\Testing;

use InvalidArgumentException;
use Orion\Adapter\SchemaAdapter;
use Orion\Adapter\SearchAdapter;
use Orion\Adapter\WriteAdapter;
use Orion\IndexName;
use Orion\Orion;
use Orion\Query\Filter;
use Orion\Query\Hit;
use Orion\Query\Query;
use Orion\Query\ResultSet;
use Orion\Registry\EngineRegistry;
use Orion\Schema\IndexSchema;
use Orion\Storage\Storage;
use Orion\Tenancy\Tenancy;
use Orion\Wiring\Config;
use Orion\Wiring\Graph;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function array_map;
use function array_unique;
use function bin2hex;
use function random_bytes;
use function sort;

/**
 * The tenancy acceptance gate (docs/spec/revision.md §7, WP6): on a shared index, no query returns a foreign tenant's
 * document, the escape hatch included. Two tenants hold documents with the same ids and overlapping text; the
 * foreign one is always the better match. Every search runs as `acme` through Orion's searcher, and every hit,
 * facet bucket and highlight must be `acme`'s. Each engine adapter runs this case against its own server.
 */
abstract class TenancyIsolationTestCase extends TestCase
{
    public const string ENGINE = 'engine';
    private const string ORG_KEY = 'org_id';
    private const string ACME = 'acme';
    private const string GLOBEX = 'globex';

    private SearchAdapter&SchemaAdapter&WriteAdapter $engine;
    private IndexName $alias;
    private SettableResolver $resolver;
    private Graph $graph;

    abstract protected function createEngine(): SearchAdapter&SchemaAdapter&WriteAdapter;

    /**
     * A native query matching every document in the index, in the adapter's escape-hatch shape.
     */
    abstract protected function nativeMatchAll(): callable;

    /**
     * Further native queries that would match foreign documents unscoped, by name.
     *
     * @return array<string, callable>
     */
    protected function nativeShapes(): array
    {
        return [];
    }

    /**
     * Native queries that read across the index and must be refused under a tenant, by name.
     *
     * @return array<string, callable>
     */
    protected function nativeScopeEscapes(): array
    {
        return [];
    }

    protected function createStorage(): Storage
    {
        return new InMemoryStorage();
    }

    protected function setUp(): void
    {
        $this->engine = $this->createEngine();
        $this->alias = IndexName::of('orion_tenancy_' . bin2hex(random_bytes(6)));
        $this->resolver = new SettableResolver();
        $this->graph = Orion::create(new Config(
            storage: $this->createStorage(),
            engines: new EngineRegistry(...[self::ENGINE => $this->engine]),
            tenancy: Tenancy::resolvedBy($this->resolver),
        ))->graph();
        $this->graph->planner(self::ENGINE)->apply($this->schema());
        $this->seed();
        $this->resolver->tenant = self::ACME;
    }

    protected function tearDown(): void
    {
        if (isset($this->graph)) {
            foreach ($this->graph->registry()->versions($this->alias) as $registration) {
                $this->engine->drop($registration->physical);
            }
        }
    }

    public function testNoQueryReturnsAForeignDocument(): void
    {
        $all = ['p1', 'p2', 'p3'];
        $queries = [
            'everything' => [$this->query(), $all],
            'text' => [$this->query()->match('title', 'running'), ['p1', 'p2']],
            'phrase' => [$this->query()->phrase('title', 'trail running'), ['p1']],
            'prefix of foreign skus' => [$this->query()->prefix('sku', 'G'), []],
            'term on a foreign value' => [$this->query()->term('brand', 'GlobexOnly'), []],
            'filter on the foreign tenant' => [$this->query()->filter(Filter::term(self::ORG_KEY, self::GLOBEX)), []],
            'either tenant' => [$this->query()->filter(Filter::terms(self::ORG_KEY, [self::ACME, self::GLOBEX])), $all],
            'should widen' => [$this->query()->filter(Filter::bool(should: [Filter::term(self::ORG_KEY, self::GLOBEX), Filter::exists('sku')])), $all],
            'must not exclude acme' => [$this->query()->filter(Filter::bool(mustNot: [Filter::term(self::ORG_KEY, self::ACME)])), []],
            'range' => [$this->query()->filter(Filter::range('stock', gte: 0)), $all],
            'sorted and paged' => [$this->query()->sort('sku')->paginate(1, 2), ['p1', 'p2']],
            'second page' => [$this->query()->sort('sku')->paginate(2, 2), ['p3']],
            'nearest is foreign' => [$this->query()->vector('embedding', [0.0, 1.0, 0.0], 10), $all],
            // Every foreign document is nearer: a tenant filter applied after the top k would leave nothing.
            'the k nearest are all foreign' => [$this->query()->vector('embedding', [0.0, 1.0, 0.2], 2), ['p2', 'p3']],
            'nearest, filtered to the foreign tenant' => [$this->query()->vector('embedding', [0.0, 1.0, 0.0], 10)->filter(Filter::term(self::ORG_KEY, self::GLOBEX)), []],
        ];

        foreach ($queries as $name => [$query, $expected]) {
            $results = $this->graph->searcher()->search($query);
            $this->assertOnlyAcme($results, $name);
            self::assertSame($expected, $this->ids($results), $name);
        }
    }

    public function testFacetsAndHighlightsShowOnlyTheTenant(): void
    {
        $results = $this->graph->searcher()->search($this->query()->match('title', 'running')->facet('brand')->highlight('title'));

        $this->assertOnlyAcme($results, 'facets');
        self::assertSame(['AcmeOnly', 'Shared'], self::sorted(array_keys($results->facets['brand'] ?? [])));
        foreach ($results->hits as $hit) {
            foreach ($hit->highlights['title'] ?? [] as $fragment) {
                self::assertStringNotContainsString('Globex', $fragment);
            }
        }
    }

    public function testTheEscapeHatchReturnsNoForeignDocument(): void
    {
        foreach (['match all' => $this->nativeMatchAll(), ...$this->nativeShapes()] as $name => $native) {
            $this->assertOnlyAcme($this->graph->searcher()->raw($this->alias, $native), 'native ' . $name);
        }
        self::assertSame(['p1', 'p2', 'p3'], $this->ids($this->graph->searcher()->raw($this->alias, $this->nativeMatchAll())));
    }

    public function testAnEscapeHatchReadingAcrossTheIndexIsRefused(): void
    {
        foreach ($this->nativeScopeEscapes() as $name => $native) {
            try {
                $this->graph->searcher()->raw($this->alias, $native);
                self::fail('The escape hatch ran ' . $name . ' under a tenant.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        self::addToAssertionCount(1);
    }

    public function testTenantsSharingAnIdNeverTouchEachOthersDocument(): void
    {
        $this->graph->outbox()->index($this->alias, 'p1', ['title' => 'Acme renamed', 'brand' => 'Shared', 'sku' => 'A-1', 'stock' => 1]);
        $this->graph->outbox()->delete($this->alias, 'p2');
        $this->drain();

        $this->resolver->tenant = self::GLOBEX;
        $globex = $this->graph->searcher()->search($this->query());

        self::assertSame(['p1', 'p2', 'p3', 'p4'], $this->ids($globex));
        foreach ($globex->hits as $hit) {
            self::assertSame(self::GLOBEX, $hit->source[self::ORG_KEY]);
            self::assertStringStartsWith('Globex', (string) $hit->source['title']);
        }
    }

    public function testIsolationHoldsAfterAReindex(): void
    {
        $this->graph->reindexer(self::ENGINE)->reindex($this->schema()->keyword('colour', filterable: true));
        $this->drain();

        $this->assertOnlyAcme($this->graph->searcher()->search($this->query()), 'after a reindex');
        self::assertSame(['p1', 'p2', 'p3'], $this->ids($this->graph->searcher()->search($this->query())));
    }

    /**
     * The alias the tenants share, for native queries that name it.
     */
    protected function indexName(): string
    {
        return $this->alias->value;
    }

    protected function schema(): IndexSchema
    {
        return IndexSchema::for($this->alias->value)
            ->text('title', 'en')
            ->keyword('brand', filterable: true, facetable: true)
            ->keyword('sku', filterable: true, sortable: true)
            ->integer('stock', filterable: true)
            ->vector('embedding', 3)
            ->orgKey(self::ORG_KEY);
    }

    /**
     * Each tenant's documents; globex's are the better match for every query above.
     */
    private function seed(): void
    {
        $corpus = [
            self::ACME => [
                'p1' => ['title' => 'Acme trail running shoe', 'brand' => 'AcmeOnly', 'sku' => 'A-1', 'stock' => 3, 'embedding' => [1.0, 0.0, 0.0]],
                'p2' => ['title' => 'Acme road running shoe', 'brand' => 'Shared', 'sku' => 'A-2', 'stock' => 5, 'embedding' => [0.9, 0.1, 0.0]],
                'p3' => ['title' => 'Acme hiking boot', 'brand' => 'Shared', 'sku' => 'A-3', 'stock' => 0, 'embedding' => [0.8, 0.0, 0.2]],
            ],
            self::GLOBEX => [
                'p1' => ['title' => 'Globex trail running trail running shoe', 'brand' => 'GlobexOnly', 'sku' => 'G-1', 'stock' => 9, 'embedding' => [0.0, 1.0, 0.0]],
                'p2' => ['title' => 'Globex running running shoe', 'brand' => 'GlobexOnly', 'sku' => 'G-2', 'stock' => 9, 'embedding' => [0.0, 0.99, 0.01]],
                'p3' => ['title' => 'Globex hiking boot', 'brand' => 'Shared', 'sku' => 'G-3', 'stock' => 9, 'embedding' => [0.0, 0.9, 0.1]],
                'p4' => ['title' => 'Globex running sock', 'brand' => 'GlobexOnly', 'sku' => 'G-4', 'stock' => 9, 'embedding' => [0.0, 1.0, 0.0]],
            ],
        ];
        foreach ($corpus as $tenant => $documents) {
            $this->resolver->tenant = $tenant;
            foreach ($documents as $id => $fields) {
                $this->graph->outbox()->index($this->alias, $id, $fields);
            }
        }
        $this->drain();
    }

    private function drain(): void
    {
        $this->graph->drain()->run($this->alias);
        $this->engine->flush($this->graph->aliases()->target($this->alias), await: true);
    }

    private function query(): Query
    {
        return Query::on($this->alias->value)->paginate(1, 50);
    }

    private function assertOnlyAcme(ResultSet $results, string $name): void
    {
        foreach ($results->hits as $hit) {
            self::assertSame(self::ACME, $hit->source[self::ORG_KEY] ?? null, $name . ' returned ' . $hit->id . ' of another tenant.');
            self::assertStringStartsWith('Acme', (string) ($hit->source['title'] ?? ''), $name);
        }
        self::assertLessThanOrEqual(3, $results->total, $name . ' counted foreign documents.');
    }

    /**
     * @return list<string>
     */
    private function ids(ResultSet $results): array
    {
        return self::sorted(array_unique(array_map(static fn(Hit $hit): string => $hit->id, $results->hits)));
    }

    /**
     * @param array<string> $values
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        $values = array_map('strval', $values);
        sort($values);

        return $values;
    }
}
