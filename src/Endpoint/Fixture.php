<?php

declare(strict_types=1);

namespace Orion\Testing\Endpoint;

use PHPUnit\Framework\Assert;

use function array_is_list;
use function array_values;
use function count;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function getenv;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function ksort;
use function mkdir;
use function sprintf;
use function str_replace;
use function strcmp;
use function strtolower;
use function usort;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * The request and response steps one fixture test produced through the bare PSR-15 harness, replayed through every
 * host and engine: each response is compared, normalised, to the recorded one at the same step. Recorded when
 * `ORION_RECORD_FIXTURES` is set and the file does not exist yet.
 */
final class Fixture
{
    public const string RECORD = 'ORION_RECORD_FIXTURES';
    private const array VOLATILE_HEADERS = ['date'];
    /** Values an engine or a run mints: scores and timings never compare across engines. */
    private const array VOLATILE_KEYS = ['score', 'took_ms'];

    /** @var list<array{request: array<string, mixed>, response: array<string, mixed>, ordered: bool}> */
    private array $steps;
    private int $cursor = 0;
    private ?string $recording = null;

    /**
     * @param list<array{request: array<string, mixed>, response: array<string, mixed>, ordered: bool}> $steps
     * @param list<string> $transportHeaders
     */
    private function __construct(private readonly string $test, array $steps, private readonly array $transportHeaders)
    {
        $this->steps = $steps;
    }

    public static function directory(): string
    {
        return dirname(__DIR__, 2) . '/fixtures/endpoints';
    }

    /**
     * @param list<string> $transportHeaders
     */
    public static function for(string $test, array $transportHeaders = []): self
    {
        $file = self::directory() . '/' . str_replace(['\\', '::'], '.', $test) . '.json';
        if (!is_file($file)) {
            if (getenv(self::RECORD) === false) {
                Assert::fail(sprintf('%s has no recorded fixture at %s; record it through the PSR-15 harness with %s=1.', $test, $file, self::RECORD));
            }
            $fixture = new self($test, [], $transportHeaders);
            $fixture->recording = $file;

            return $fixture;
        }
        /** @var list<array{request: array<string, mixed>, response: array<string, mixed>, ordered: bool}> $steps */
        $steps = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

        return new self($test, $steps, $transportHeaders);
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $response
     * @param bool $ordered whether the order of the hits is contractual (the request sorted them)
     */
    public function compare(array $request, array $response, bool $ordered): void
    {
        if ($this->recording !== null) {
            Assert::assertArrayHasKey('status', $response);
            $this->steps[] = ['request' => $request, 'response' => $response, 'ordered' => $ordered];

            return;
        }
        $step = $this->steps[$this->cursor] ?? null;
        Assert::assertNotNull($step, sprintf('%s: step %d was not recorded (the test issues more requests than before).', $this->test, $this->cursor + 1));
        $label = sprintf('%s: step %d %s %s', $this->test, $this->cursor + 1, (string) ($request['method'] ?? ''), (string) ($request['path'] ?? ''));
        Assert::assertSame(self::plain($step['request']), self::plain($request), $label . ' (request)');
        Assert::assertSame(self::normalise($step['response'], $this->transportHeaders, $ordered), self::normalise($response, $this->transportHeaders, $ordered), $label);
        ++$this->cursor;
    }

    public function assertConsumed(): void
    {
        if ($this->recording !== null) {
            if (!is_dir(dirname($this->recording))) {
                mkdir(dirname($this->recording), 0777, true);
            }
            file_put_contents($this->recording, json_encode($this->steps, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");

            return;
        }
        Assert::assertSame(count($this->steps), $this->cursor, sprintf('%s: %d requests were recorded, the replay issued %d.', $this->test, count($this->steps), $this->cursor));
    }

    /**
     * Comparable across runs, hosts and engines: header names lower-cased and the volatile ones dropped, scores and
     * timings masked, hits in id order unless the request ordered them, maps in key order.
     *
     * @param array<string, mixed> $response
     * @param list<string> $transportHeaders
     * @return array<string, mixed>
     */
    public static function normalise(array $response, array $transportHeaders, bool $ordered): array
    {
        $headers = [];
        foreach ((array) ($response['headers'] ?? []) as $name => $values) {
            $lower = strtolower((string) $name);
            if (!in_array($lower, self::VOLATILE_HEADERS, true) && !in_array($lower, $transportHeaders, true)) {
                $headers[$lower] = array_values((array) $values);
            }
        }
        ksort($headers);
        $body = $response['body'] ?? null;
        if (is_array($body) && isset($body['hits']) && is_array($body['hits']) && !$ordered) {
            usort($body['hits'], static fn(mixed $a, mixed $b): int => strcmp((string) ((array) $a)['id'], (string) ((array) $b)['id']));
        }
        if (is_array($body) && isset($body['fusion']['contributions']) && is_array($body['fusion']['contributions'])) {
            usort($body['fusion']['contributions'], static fn(mixed $a, mixed $b): int => strcmp((string) ((array) $a)['source'] . (string) ((array) $a)['id'], (string) ((array) $b)['source'] . (string) ((array) $b)['id']));
        }

        return ['status' => $response['status'] ?? null, 'headers' => $headers, 'body' => self::value($body, null)];
    }

    /**
     * As the fixture file holds it: through JSON, so a whole float and an int compare equal.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private static function plain(array $request): array
    {
        /** @var array<string, mixed> */
        return json_decode(json_encode($request, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    private static function value(mixed $value, ?string $key): mixed
    {
        if (is_array($value)) {
            $copy = [];
            foreach ($value as $k => $v) {
                $copy[$k] = self::value($v, is_string($k) ? $k : $key);
            }
            if (!array_is_list($copy)) {
                ksort($copy);
            }

            return $copy;
        }
        if ((is_float($value) || is_int($value)) && $key !== null && in_array($key, self::VOLATILE_KEYS, true)) {
            return '<' . $key . '>';
        }

        return $value;
    }
}
