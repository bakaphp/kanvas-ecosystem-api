<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Variants\Models\Variants;
use Laravel\Scout\Builder as ScoutBuilder;

class VariantSearchService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function search(
        Apps $app,
        Companies $company,
        string $keyword,
        int $limit = 20
    ): array {
        $search = Variants::traitSearch($keyword);
        $search->model->setRelation('app', $app);

        $this->scopeSearch($search, $app, $company);

        $variants = $search->take($limit)->get();

        $variants->load([
            'product',
            'channels',
            'variantAttributes.attribute',
        ]);

        return $variants->map(fn (Variants $variant) => [
            'id' => $variant->getId(),
            'name' => $variant->name,
            'sku' => $variant->sku,
            'product' => $variant->product?->name,
            'is_published' => (bool) $variant->is_published,
            'stock' => $variant->getTotalQuantity(),
            'price' => $variant->defaultChannelPrice(),
            'attributes' => $variant->loadedSearchableAttributes()
                ->mapWithKeys(fn (array $attribute): array => [$attribute['name'] => $attribute['value']])
                ->all(),
        ])->toArray();
    }

    private function scopeSearch(ScoutBuilder $search, Apps $app, Companies $company): void
    {
        if ($search->model->isTypesense()) {
            $search
                ->where('apps_id', $app->getId())
                ->where('company.id', $company->getId())
                ->options([
                    'query_by' => 'name,sku,ean,barcode,description,short_description',
                ]);

            return;
        }

        $search->query(fn ($query) => $query
            ->where('apps_id', $app->getId())
            ->whereRelation('product', 'companies_id', $company->getId()));
    }
}
