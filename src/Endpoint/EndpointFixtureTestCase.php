<?php

declare(strict_types=1);

namespace Orion\Testing\Endpoint;

use Orion\Adapter\InMemory\InMemoryEngine;
use Orion\Adapter\SchemaAdapter;
use Orion\Adapter\SearchAdapter;
use Orion\Adapter\WriteAdapter;
use Orion\IndexName;
use Orion\Lifecycle\IndexNamer;
use Orion\Testing\FaultyEngine;
use Orion\Wiring\Graph;
use PHPUnit\Framework\TestCase;

use function array_sum;
use function dirname;
use function is_array;
use function str_repeat;

/**
 * The endpoint acceptance gate (docs/spec/revision.md §7, WP8): the `search:` endpoints of `fixtures/manifests`,
 * served to two tenants over one shared index, answer exactly as recorded, through every host (the bare PSR-15
 * pipeline, the Univeros module) and on every engine. Requests name their tenant and user the way the host's
 * authentication would resolve them; `acme`'s user `u1` holds the `products.search` permission, `u2` does not.
 * Scores and timings are masked; the order of hits is compared only where the request sorted them.
 */
abstract class EndpointFixtureTestCase extends TestCase
{
    public const string ENGINE = 'engine';
    private const int LEFTOVER_VERSIONS = 3;
    /** The most documents either tenant holds in an index. */
    private const int OWN_DOCUMENTS = 4;
    public const string ACME = 'acme';
    public const string GLOBEX = 'globex';
    public const string ALLOWED_USER = 'u1';
    public const string OTHER_USER = 'u2';
    public const string TENANT_HEADER = 'X-Tenant';
    public const string USER_HEADER = 'X-User';

    private Harness $harness;
    private FaultyEngine $engine;
    private Fixture $fixture;

    abstract protected static function harnessClass(): string;

    protected function createEngine(): SearchAdapter&SchemaAdapter&WriteAdapter
    {
        return new InMemoryEngine();
    }

    public static function manifestDirectory(): string
    {
        return dirname(__DIR__, 2) . '/fixtures/manifests';
    }

    protected function setUp(): void
    {
        $this->engine = new FaultyEngine($this->createEngine());
        /** @var class-string<Harness> $class */
        $class = static::harnessClass();
        $this->harness = $class::create($this->engine, self::manifestDirectory());
        // Keyed by the test, not the host or engine running it: one recording proves every harness.
        $this->fixture = Fixture::for(self::class . '::' . $this->name(), $class::transportHeaders());
        $graph = $this->harness->graph();
        foreach ($graph->manifest()->declarations() as $declaration) {
            // The manifests name their indices, so a real engine may still hold a previous run's.
            for ($version = 1; $version <= self::LEFTOVER_VERSIONS; ++$version) {
                $this->engine->drop(IndexNamer::physical($declaration->schema->index, $version));
            }
            $graph->planner(self::ENGINE)->apply($declaration->schema);
        }
        $this->seed($graph);
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->assertConsumed();
        }
        if (isset($this->harness)) {
            foreach ($this->harness->graph()->manifest()->declarations() as $declaration) {
                foreach ($this->harness->graph()->registry()->versions($declaration->schema->index) as $registration) {
                    $this->engine->drop($registration->physical);
                }
            }
        }
    }

    public function testEachTenantSearchesItsOwnDocumentsOnly(): void
    {
        $this->get('/products/search', [], self::ACME);
        $this->get('/products/search', [], self::GLOBEX);
        $this->get('/products/search', ['q' => 'running'], self::ACME);
        $this->get('/products/search', ['q' => 'running'], self::GLOBEX);
        $this->get('/products/search', ['q' => 'globex'], self::ACME);
    }

    public function testFiltersSortsAndPagesReadWhatTheEndpointDeclares(): void
    {
        $this->get('/products/search', ['filter' => ['brand' => 'Shared'], 'sort' => '-sku', 'size' => '1'], self::ACME, ordered: true);
        $this->get('/products/search', ['filter' => ['brand' => 'Shared'], 'sort' => '-sku', 'size' => '1', 'page' => '2'], self::ACME, ordered: true);
        $this->get('/products/search', ['filter' => ['brand' => ['AcmeOnly', 'GlobexOnly']], 'sort' => 'sku'], self::ACME, ordered: true);
        $this->get('/products/search', ['filter' => ['stock' => ['gte' => '1', 'lt' => '5']], 'sort' => 'stock,_score'], self::ACME, ordered: true);
        $this->get('/products/search', ['filter' => ['sku' => 'G-1']], self::ACME);
        $this->get('/products/search', ['q' => 'shoe', 'filter' => ['stock' => '5']], self::ACME);
    }

    public function testFacetsAndHighlightsShowTheTenantOnly(): void
    {
        $this->get('/products/search', ['q' => 'running'], self::GLOBEX);
    }

    public function testARequestWithoutATenantIsUnauthorized(): void
    {
        $this->get('/products/search', ['q' => 'running'], null);
    }

    public function testARequestWithoutThePermissionIsForbidden(): void
    {
        $this->get('/products/search', ['q' => 'running'], self::ACME, self::OTHER_USER);
    }

    public function testInputThatDoesNotFitIsRefusedWithItsPath(): void
    {
        $this->get('/products/search', ['filter' => ['title' => 'x']], self::ACME);
        $this->get('/products/search', ['filter' => ['stock' => 'many']], self::ACME);
        $this->get('/products/search', ['filter' => ['stock' => ['between' => '1']]], self::ACME);
        $this->get('/products/search', ['sort' => 'brand'], self::ACME);
        $this->get('/products/search', ['page' => '0'], self::ACME);
        $this->get('/products/search', ['size' => '11'], self::ACME);
        $this->get('/products/search', ['limit' => '5'], self::ACME);
        $this->get('/products/search', ['page' => '1001'], self::ACME);
        $this->get('/products/search', ['vector' => ['1']], self::ACME);
        $this->get('/products/search', ['sort' => 'sku,-sku'], self::ACME);
        // One past the 64 words a `q` may carry (orion-search/psr15's SearchRequest::MAX_TERMS; not imported, the testing package precedes it).
        $this->get('/products/search', ['q' => str_repeat('shoe ', 65)], self::ACME);
    }

    public function testTheOrgKeyIsNoRequestsToName(): void
    {
        $this->get('/products/search', ['filter' => ['org_id' => self::GLOBEX]], self::ACME);
        $this->get('/products/search', ['sort' => 'org_id'], self::ACME);
        $this->post('/items/search', ['filter' => ['org_id' => [self::ACME, self::GLOBEX]]], self::ACME);
    }

    public function testTheHybridEndpointFusesBothLegsAsTheTenant(): void
    {
        // Query vectors chosen so no two of a tenant's documents tie: engines break ties differently.
        $this->post('/items/search', ['q' => 'running', 'vector' => [0.5, 0.5, 0.0]], self::ACME);
        $this->post('/items/search', ['vector' => [0.0, 1.0, 0.2], 'size' => 2], self::ACME);
        $this->post('/items/search', ['q' => 'boot', 'vector' => [0.0, 1.0, 0.0], 'filter' => ['brand' => 'Shared']], self::GLOBEX);
        $this->post('/items/search', ['vector' => [1.0]], self::ACME);
        $this->post('/items/search', ['q' => 'running', 'vector' => [0.5, 0.5, 0.0], 'sort' => '_score'], self::ACME);
        $this->post('/items/search', ['q' => 'running', 'vector' => [0.5, 0.5, 0.0], 'sort' => '-_score', 'size' => 2], self::ACME, ordered: true);
    }

    public function testAMalformedBodyIsRefused(): void
    {
        $this->request(['method' => 'POST', 'path' => '/items/search', 'body' => null, 'raw' => '{"q":'], self::ACME);
        $this->request(['method' => 'POST', 'path' => '/items/search', 'body' => null, 'raw' => '[1,2]'], self::ACME);
        $this->request(['method' => 'POST', 'path' => '/items/search', 'body' => null, 'raw' => ''], self::ACME);
    }

    public function testADeclaredPathWithAnotherMethodIsNotAllowedAndOthersPassThrough(): void
    {
        $this->request(['method' => 'GET', 'path' => '/items/search'], self::ACME);
        $this->request(['method' => 'POST', 'path' => '/products/search', 'body' => []], self::ACME);
        $this->request(['method' => 'GET', 'path' => '/products'], self::ACME);
    }

    public function testAnEngineFailureIsAProblemThatLeaksNothing(): void
    {
        $this->engine->killBefore(FaultyEngine::SEARCH);

        $this->get('/products/search', ['q' => 'running'], self::ACME);
    }

    /**
     * @param array<string, mixed> $query
     */
    protected function get(string $path, array $query, ?string $tenant, string $user = self::ALLOWED_USER, bool $ordered = false): void
    {
        $this->request(['method' => 'GET', 'path' => $path, 'query' => $query], $tenant, $user, $ordered);
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function post(string $path, array $body, ?string $tenant, string $user = self::ALLOWED_USER, bool $ordered = false): void
    {
        $this->request(['method' => 'POST', 'path' => $path, 'body' => $body], $tenant, $user, $ordered);
    }

    /**
     * @param array<string, mixed> $request
     */
    private function request(array $request, ?string $tenant, string $user = self::ALLOWED_USER, bool $ordered = false): void
    {
        $headers = [self::USER_HEADER => $user, ...($tenant === null ? [] : [self::TENANT_HEADER => $tenant])];
        /** @var array{method: string, path: string, query?: array<string, mixed>, headers?: array<string, string>, body?: array<string, mixed>|null, raw?: string} $request */
        $request = [...$request, 'headers' => $headers];
        $response = $this->harness->handle($request);
        self::assertOwn($response, $tenant, $request['method'] . ' ' . $request['path']);
        $this->fixture->compare($request, $response, $ordered);
    }

    /**
     * The invariant no recording can bless away: every hit, facet bucket and fusion contribution is the tenant's.
     *
     * @param array{status: int, headers: array<string, list<string>>, body: mixed} $response
     */
    private static function assertOwn(array $response, ?string $tenant, string $label): void
    {
        $body = is_array($response['body']) ? $response['body'] : [];
        if ($response['status'] !== 200) {
            self::assertArrayNotHasKey('hits', $body, $label);

            return;
        }
        self::assertNotNull($tenant, $label . ' answered a request without a tenant');
        foreach ((array) ($body['hits'] ?? []) as $hit) {
            self::assertSame($tenant, ((array) ((array) $hit)['source'])['org_id'] ?? null, $label . ' returned another tenant\'s document');
        }
        foreach ((array) ($body['facets'] ?? []) as $field => $buckets) {
            self::assertLessThanOrEqual(self::OWN_DOCUMENTS, (int) array_sum((array) $buckets), $label . ' counted more than the tenant holds in ' . (string) $field);
        }
        self::assertLessThanOrEqual(self::OWN_DOCUMENTS, (int) ($body['total'] ?? 0), $label . ' counted foreign documents');
    }

    /**
     * Each tenant's documents, under the same ids; globex's are the better match for every query.
     */
    private function seed(Graph $graph): void
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
        foreach ($graph->manifest()->declarations() as $declaration) {
            $index = $declaration->schema->index;
            foreach ($corpus as $tenant => $documents) {
                $this->harness->as($tenant, self::ALLOWED_USER, static function () use ($graph, $index, $documents): void {
                    foreach ($documents as $id => $fields) {
                        $graph->outbox()->index($index, $id, $fields);
                    }
                });
            }
            $graph->drain()->run($index);
            $this->engine->flush($graph->aliases()->target($index), await: true);
        }
    }
}
