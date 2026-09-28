<?php

declare(strict_types=1);

namespace Baka\Search;

use Baka\Search\Contracts\NameSearchInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Override;
use Typesense\Client as TypesenseClient;

/**
 * The whole name list in one /multi_search instead of a request per name. Same per-name isolation as
 * EngineNameSearch — each search carries its own q and its own per_page — without paying N round
 * trips for a spreadsheet cross-reference.
 */
class TypesenseNameSearch implements NameSearchInterface
{
    /** Typesense accepts more, but a 100-name batch in one body is a needlessly large request. */
    private const int SEARCHES_PER_REQUEST = 25;

    public function __construct(
        private readonly TypesenseClient $client,
    ) {
    }

    #[Override]
    public function idsFor(
        Model $model,
        Apps $app,
        Companies $company,
        string $queryBy,
        array $terms,
        int $perTerm,
    ): array {
        if ($terms === []) {
            return [];
        }

        // searchableAs() reads the app's custom-index override off the model's own app relation;
        // without priming it, it silently falls back to whatever app the container holds.
        $model->setRelation('app', $app);

        $collection = $model->searchableAs();
        $filter = sprintf('apps_id:=%d && companies_id:=%d', $app->getId(), $company->getId());

        $ids = [];

        foreach (array_chunk($terms, self::SEARCHES_PER_REQUEST) as $chunk) {
            $searches = array_map(
                fn (array $term): array => [
                    'collection' => $collection,
                    'q' => $term['query'],
                    'query_by' => $queryBy,
                    'filter_by' => $filter,
                    'per_page' => $perTerm,
                ],
                $chunk,
            );

            $response = $this->client->multiSearch->perform(['searches' => $searches]);

            foreach ($response['results'] ?? [] as $result) {
                foreach ($result['hits'] ?? [] as $hit) {
                    $id = $hit['document']['id'] ?? null;

                    if ($id !== null) {
                        $ids[] = (string) $id;
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }
}
