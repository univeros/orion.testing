# orion-search/testing

What a test suite needs to run Orion without an engine or a database: the in-memory storage adapter,
a fake clock, and the conformance test cases every engine adapter (`EngineConformanceTestCase`) and
every storage adapter (`StorageConformanceTestCase`) must extend and pass before it ships, plus the
fault-injection harness used by the determinism and lossless-reindex proofs.
