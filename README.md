# orion-search/testing

What a test suite needs to run Orion without an engine or a database, and what every adapter must pass before it
ships:

- `InMemoryStorage`, the reference `Storage` adapter.
- `StorageConformanceTestCase`: the criteria grammar, identifier and value safety, and the same-connection proof.
  The outbox row written through the adapter commits and rolls back with an entity row written on the host's own
  connection, inside the host's own transaction. Implement `createStorage()`, `insertOnHostConnection()` and
  `inHostTransaction()`.
- `EngineConformanceTestCase`: schema create, inspect and in-place apply without drift; version-stamped writes and
  deletes that converge; the neutral query AST with portable semantics; the scoped escape hatch; `UnsupportedClause`
  for every capability reported false. Implement `createEngine()` and `nativeMatchAll()`.

The fault-injection harness for the lossless-reindex proofs joins in WP4.
