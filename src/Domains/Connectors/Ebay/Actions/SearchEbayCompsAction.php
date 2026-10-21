<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Ebay\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Ebay\Client;

final class SearchEbayCompsAction
{
    public function __construct(
        private Apps $app,
        private Companies $company,
        private string $query,
        private int $limit = 50,
    ) {
    }

    /** @return array<string, mixed> */
    public function execute(): array
    {
        $response = (new Client($this->app, $this->company))->searchComparableItems($this->query, $this->limit);
        $items = $response['itemSummaries'] ?? [];
        $items = is_array($items) ? $items : [];
        $prices = [];

        foreach ($items as $item) {
            $price = $item['price']['value'] ?? null;
            if (is_numeric($price)) {
                $prices[] = (float) $price;
            }
        }

        sort($prices);

        return [
            'query' => $this->query,
            'source' => 'active_listings',
            'count' => count($prices),
            'average_price' => $prices === [] ? null : array_sum($prices) / count($prices),
            'median_price' => $this->median($prices),
            'items' => $items,
        ];
    }

    /** @param list<float> $prices */
    private function median(array $prices): ?float
    {
        $count = count($prices);
        if ($count === 0) {
            return null;
        }

        $middle = intdiv($count, 2);

        return $count % 2 === 0
            ? ($prices[$middle - 1] + $prices[$middle]) / 2
            : $prices[$middle];
    }
}
