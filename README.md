# orion-search/testing

What a test suite needs to run Orion without an engine or a database, and what every adapter must pass before it
ships.

```sh
composer require --dev orion-search/testing
```

```php
use Orion\Adapter\InMemory\InMemoryEngine;
use Orion\Orion;
use Orion\Registry\EngineRegistry;
use Orion\Tenancy\Tenancy;
use Orion\Testing\FixedClock;
use Orion\Testing\InMemoryStorage;
use Orion\Wiring\Config;

$graph = Orion::create(new Config(
    storage: new InMemoryStorage(),
    engines: new EngineRegistry(memory: new InMemoryEngine()),
    tenancy: Tenancy::single(),
    clock: new FixedClock(),
))->graph();
```

For application tests:

- `InMemoryStorage`, the reference `Storage` adapter: Orion's tables over PHP arrays, nothing to install,
  transactions that roll back, SQL's comparison rules.
- `FixedClock` (one instant) and `SettableClock` (moved with `advance()`), for timestamps, leases and grace windows.
- `SettableResolver`, a tenant resolver a test switches by hand; with no tenant set it fails, as a request without
  one must.

For adapter authors, the suites every adapter in this repository passes:

- `StorageConformanceTestCase`: the criteria grammar, identifier and value safety, and the same-connection proof.
  The outbox row written through the adapter commits and rolls back with an entity row written on the host's own
  connection, inside the host's own transaction. Implement `createStorage()`, `insertOnHostConnection()` and
  `inHostTransaction()`.
- `EngineConformanceTestCase`: schema create, inspect and in-place apply without drift; version-stamped writes and
  deletes that converge; the neutral query AST with portable semantics; the scoped escape hatch; `UnsupportedClause`
  for every capability reported false. Implement `createEngine()` and `nativeMatchAll()`.
- `TenancyIsolationTestCase`: no query, neutral or native, returns, overwrites or deletes another tenant's document.
- `LifecycleDeterminismTestCase` and `FaultInjectionTestCase` (with `FaultyEngine` and `FaultyStorage`): plan,
  apply and reindex are reproducible, and a reindex is lossless when the engine or the database dies mid-operation.
- `Equivalence\QueryEquivalenceTestCase`: seeded random queries (`ORION_SEED`) answer as the in-memory oracle does.
- `Endpoint\EndpointFixtureTestCase`: a host serves the recorded `search:` endpoint fixtures (`fixtures/endpoints`)
  exactly.

How to test an application that uses Orion: the [Testing guide](../../docs/guides/testing.md).
