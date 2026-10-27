<?php

declare(strict_types=1);

namespace Orion\Testing\Endpoint;

use Closure;
use Orion\Adapter\SchemaAdapter;
use Orion\Adapter\SearchAdapter;
use Orion\Adapter\WriteAdapter;
use Orion\Wiring\Graph;

/**
 * What the endpoint fixtures run through (docs/spec/revision.md §5, §7): the bare PSR-15 pipeline, or a host that
 * boots a real application around the same engine and manifests, so the same requests and the same recorded
 * responses prove every host. Requests and responses are plain data, so the harness builds them with whatever
 * PSR-7 implementation its host has.
 */
interface Harness
{
    /**
     * Boots the host on this engine, named `engine` in its registry, reading the `search:` manifests in the directory.
     */
    public static function create(SearchAdapter&SchemaAdapter&WriteAdapter $engine, string $manifestDirectory): static;

    public function graph(): Graph;

    /**
     * @param array{method: string, path: string, query?: array<string, mixed>, headers?: array<string, string>, body?: array<string, mixed>|null, raw?: string} $request `raw` is a body sent as given, unencoded
     * @return array{status: int, headers: array<string, list<string>>, body: mixed} the body decoded when it is JSON
     */
    public function handle(array $request): array;

    /**
     * Runs `$work` as the tenant and user named, the way a request of theirs would: the fixtures seed each tenant's
     * documents through it.
     *
     * @template T
     * @param Closure(): T $work
     * @return T
     */
    public function as(string $tenant, string $user, Closure $work): mixed;

    /**
     * Lower-case names of the headers the host's transport layer adds to every response; the comparison ignores them.
     *
     * @return list<string>
     */
    public static function transportHeaders(): array;
}
