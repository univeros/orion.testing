<?php

declare(strict_types=1);

namespace Orion\Testing\Equivalence;

use Orion\Query\Direction;
use Orion\Query\Filter;
use Orion\Query\Fusion;
use Orion\Query\Query;
use Orion\Schema\IndexSchema;
use Orion\Schema\VectorMetric;
use Orion\Write\Document;

use function array_fill;
use function array_map;
use function implode;
use function sprintf;
use function substr;

/**
 * Generates corpora and queries over one schema, inside the portable subset of the neutral query (v1 §18.2): what
 * every engine answers identically, as the conformance case promises. Left out on purpose, each a documented
 * divergence rather than a portable promise: stop words and inflected words (each engine's language analysis
 * differs, so the vocabulary has neither), several words in a `match` on a keyword (Solr reads them as alternatives),
 * list values (Solr's fields are single-valued), ranking and scores (never deterministic), highlight fragments.
 */
final readonly class QueryGenerator
{
    /** Stem-stable, stop-word-free, ASCII: the same token on every analyser. */
    public const array WORDS = ['trail', 'boot', 'sock', 'shoe', 'jacket', 'glove', 'lamp', 'desk', 'chair', 'tent', 'rope', 'helmet', 'blue', 'red', 'black'];
    public const array BRANDS = ['acme', 'globex', 'initech', 'umbrella'];
    private const int TITLE_WORDS = 3;
    /** More than any corpus the case generates, so an unordered query's one page holds every match. */
    public const int WHOLE_PAGE = 100;
    private const int DIMS = 3;

    public function __construct(private Seeded $random)
    {
    }

    public static function schema(string $index): IndexSchema
    {
        return IndexSchema::for($index)
            ->text('title', language: 'en')
            ->keyword('sku', filterable: true, sortable: true)
            ->keyword('brand', filterable: true, facetable: true)
            ->float('price', filterable: true, sortable: true)
            ->integer('stock', filterable: true, sortable: true)
            ->boolean('in_stock', filterable: true)
            ->date('released', filterable: true, sortable: true)
            ->vector('embedding', dims: self::DIMS, metric: VectorMetric::Cosine);
    }

    /**
     * @return list<Document> `$count` documents with distinct skus, in-stock flags, prices, dates and vectors
     */
    public function corpus(int $count): array
    {
        $documents = [];
        for ($i = 1; $i <= $count; ++$i) {
            $stock = $this->random->chance(80) ? $this->random->int(0, 9) : null;
            $documents[] = new Document((string) $i, [
                'title' => implode(' ', $this->random->some(self::WORDS, self::TITLE_WORDS)),
                'sku' => sprintf('S%03d', $i),
                'brand' => $this->random->pick(self::BRANDS),
                'price' => $this->random->int(1, 200) + $this->random->float(),
                ...($stock === null ? [] : ['stock' => $stock]),
                'in_stock' => $stock !== null && $stock > 0,
                'released' => sprintf('2026-%02d-%02d', $this->random->int(1, 12), $this->random->int(1, 28)),
                'embedding' => $this->vector(),
            ], 1);
        }

        return $documents;
    }

    /**
     * One query: lexical or vector or both (fused), filters, an optional total order, facets and a page.
     */
    public function query(string $index): Query
    {
        $query = Query::on($index);
        $lexical = $this->random->chance(75);
        $vector = $this->random->chance(30);
        if ($lexical) {
            foreach ($this->random->some(['match', 'term', 'prefix', 'phrase'], $this->random->int(1, 2)) as $kind) {
                $query = match ($kind) {
                    'match' => $query->match($this->random->chance(50) ? 'title' : ['title', 'sku'], implode(' ', $this->random->some(self::WORDS, $this->random->int(1, 2)))),
                    'term' => $this->random->chance(50)
                        ? $query->term('brand', $this->random->pick(self::BRANDS))
                        : $query->term('sku', sprintf('S%03d', $this->random->int(1, 50))),
                    'prefix' => $this->random->chance(50)
                        ? $query->prefix('title', substr($this->random->pick(self::WORDS), 0, $this->random->int(1, 3)))
                        : $query->prefix('sku', 'S0' . $this->random->int(0, 9)),
                    default => $query->phrase('title', implode(' ', $this->random->some(self::WORDS, 2))),
                };
            }
        }
        if ($vector || !$lexical) {
            $query = $query->vector('embedding', $this->vector(), $this->random->int(1, 12));
            if ($lexical) {
                $query = $query->fuse(Fusion::reciprocalRank($this->random->pick([1, 10, 60])));
            }
        }
        for ($i = $this->random->int(0, 2); $i > 0; --$i) {
            $query = $query->filter($this->filter(1));
        }
        if ($query->vector === null && $this->random->chance(50)) {
            $query = $query->sort($this->random->pick(['price', 'stock', 'released']), $this->random->pick([Direction::Asc, Direction::Desc]))->sort('sku');
        }
        if ($this->random->chance(50)) {
            $query = $query->facet('brand', $this->random->int(1, 4));
        }
        // A page of a relevance ranking is the engine's; only a totally ordered query pages portably. Unordered
        // queries read every match on one page, so the sets compare.
        return $query->sorts !== [] ? $query->paginate($this->random->int(1, 3), $this->random->int(2, 6)) : $query->paginate(1, self::WHOLE_PAGE);
    }

    private function filter(int $depth): Filter
    {
        $kind = $this->random->pick($depth < 2 ? ['term', 'terms', 'range', 'exists', 'bool'] : ['term', 'terms', 'range', 'exists']);

        return match ($kind) {
            'term' => $this->random->chance(50)
                ? Filter::term('brand', $this->random->pick(self::BRANDS))
                : Filter::term('in_stock', $this->random->chance(50)),
            'terms' => Filter::terms('brand', $this->random->some(self::BRANDS, $this->random->int(1, 3))),
            'range' => match ($this->random->pick(['price', 'stock', 'released'])) {
                'price' => Filter::range('price', gte: $this->random->int(0, 150), lt: $this->random->int(151, 201)),
                'stock' => $this->random->chance(50) ? Filter::range('stock', gt: $this->random->int(0, 5)) : Filter::range('stock', lte: $this->random->int(0, 9)),
                default => Filter::range('released', gte: sprintf('2026-%02d-01', $this->random->int(1, 12))),
            },
            'exists' => Filter::exists('stock'),
            default => $this->bool($depth),
        };
    }

    /**
     * A composition with at least one clause, each part drawn independently.
     */
    private function bool(int $depth): Filter
    {
        $must = $this->random->chance(50) ? [$this->filter($depth + 1)] : [];
        $should = $this->random->chance(50) ? [$this->filter($depth + 1), $this->filter($depth + 1)] : [];
        $mustNot = $this->random->chance(50) ? [$this->filter($depth + 1)] : [];
        if ($must === [] && $should === [] && $mustNot === []) {
            $must = [$this->filter($depth + 1)];
        }

        return Filter::bool($must, $should, $mustNot);
    }

    /**
     * @return list<float>
     */
    private function vector(): array
    {
        return array_map(fn(): float => $this->random->float(), array_fill(0, self::DIMS, 0));
    }
}
