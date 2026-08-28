<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Traits;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Souk\Enums\ConfigurationEnum as SoukConfigurationEnum;

/**
 * The reference lists the catalog write tools depend on: a warehouse_id, channel_id, category id or
 * product_type_id the model is asked for has to be discoverable somewhere, or it invents one.
 *
 * Every list reports `total` next to `showing` and says so in `message` when the two differ. A bare
 * truncated page reads to the model as the whole catalog — the tenant this was written against has
 * 6,860 attributes and 11,713 categories behind a 20/30-row window, so "list them all" is never the
 * right mental model and the tool has to say so rather than quietly lie.
 */
trait ListsCatalogReferenceData
{
    private const int MAX_REFERENCE_ROWS = 100;

    /**
     * @return array<string, mixed>
     */
    protected function listCatalogWarehouses(?int $limit = null): array
    {
        $query = Warehouses::fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->orderByDesc('is_default')
            ->orderBy('name');

        return $this->presentCatalogReferenceList(
            $query,
            $limit,
            'warehouses',
            fn (Warehouses $warehouse) => [
                'id' => $warehouse->getId(),
                'name' => $warehouse->name,
                'location' => $warehouse->location,
                'is_default' => (bool) $warehouse->is_default,
                'is_published' => (bool) $warehouse->is_published,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function listCatalogChannels(?int $limit = null): array
    {
        $query = Channels::fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->orderByDesc('is_default')
            ->orderBy('name');

        return $this->presentCatalogReferenceList(
            $query,
            $limit,
            'channels',
            fn (Channels $channel) => [
                'id' => $channel->getId(),
                'name' => $channel->name,
                'slug' => $channel->slug,
                'is_default' => (bool) $channel->is_default,
                // The cart resolves the default channel only when it is published, so an unpublished
                // one cannot carry a sellable price no matter what is written to it.
                'is_published' => (bool) $channel->is_published,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function listCatalogProductTypes(?string $keyword = null, ?int $limit = null): array
    {
        $query = ProductsTypes::fromApp($this->app)
            ->notDeleted()
            ->orderBy('name');

        $this->scopeCatalogReferenceToCompany($query, allowGlobal: true);
        $this->filterCatalogReferenceByName($query, $keyword);

        return $this->presentCatalogReferenceList(
            $query,
            $limit,
            'product_types',
            fn (ProductsTypes $productType) => [
                'id' => $productType->getId(),
                'name' => $productType->name,
                'slug' => $productType->slug,
            ],
            $keyword,
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function listCatalogCategories(?string $keyword = null, ?int $limit = null): array
    {
        $query = Categories::fromApp($this->app)
            ->notDeleted()
            ->orderBy('name');

        $this->scopeCatalogReferenceToCompany($query, allowGlobal: false);
        $this->filterCatalogReferenceByName($query, $keyword);

        return $this->presentCatalogReferenceList(
            $query,
            $limit,
            'categories',
            fn (Categories $category) => [
                'id' => $category->getId(),
                'name' => $category->name,
                'slug' => $category->slug,
                'parent_id' => $category->parent_id,
                'is_published' => (bool) $category->is_published,
            ],
            $keyword,
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function listCatalogAttributes(?string $keyword = null, ?int $limit = null): array
    {
        $query = Attributes::fromApp($this->app)
            ->notDeleted()
            ->with(['attributeType', 'defaultValues'])
            ->orderBy('name');

        $this->scopeCatalogReferenceToCompany($query, allowGlobal: true);
        $this->filterCatalogReferenceByName($query, $keyword);

        return $this->presentCatalogReferenceList(
            $query,
            $limit,
            'attributes',
            fn (Attributes $attribute) => [
                'id' => $attribute->getId(),
                'name' => $attribute->name,
                'slug' => $attribute->slug,
                'type' => $attribute->attributeType?->name,
                'is_filterable' => (bool) $attribute->is_filterable,
                'is_searchable' => (bool) $attribute->is_searchable,
                'allowed_values' => $attribute->defaultValues->pluck('value')->filter()->values()->toArray(),
            ],
            $keyword,
        );
    }

    /**
     * Attributes and product types ship app-global rows (companies_id 0) every tenant sees; categories
     * and the rest are company-owned. Cross-company variants, when the app enables them, widen the
     * company-owned lists the same way the existing inventory tools do.
     */
    private function scopeCatalogReferenceToCompany(Builder $query, bool $allowGlobal): void
    {
        if ((bool) $this->app->get(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value)) {
            return;
        }

        $allowGlobal
            ? $query->whereIn('companies_id', [0, $this->company->getId()])
            : $query->where('companies_id', $this->company->getId());
    }

    private function filterCatalogReferenceByName(Builder $query, ?string $keyword): void
    {
        $keyword = trim((string) $keyword);

        if ($keyword !== '') {
            $query->where('name', 'like', '%' . $keyword . '%');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function presentCatalogReferenceList(
        Builder $query,
        ?int $limit,
        string $key,
        callable $present,
        ?string $keyword = null,
    ): array {
        // The rows key doubles as the noun in the message; a spaced key would be awkward JSON.
        $label = str_replace('_', ' ', $key);
        $limit = max(1, min($limit ?? 25, self::MAX_REFERENCE_ROWS));
        // Counted on a clone: getCountForPagination() rewrites columns and bindings on the underlying
        // query, and the same builder still has to run the row fetch below.
        $total = (clone $query)->toBase()->getCountForPagination();
        $rows = $query->limit($limit)->get()->map($present)->values()->toArray();

        $keyword = trim((string) $keyword);
        $scope = $keyword === '' ? '' : sprintf(' matching "%s"', $keyword);

        if ($rows === []) {
            return [
                'total' => 0,
                'showing' => 0,
                $key => [],
                'message' => sprintf('No %s found%s in this company.', $label, $scope),
            ];
        }

        return [
            'total' => $total,
            'showing' => count($rows),
            $key => $rows,
            'message' => $total > count($rows)
                ? sprintf(
                    'Showing %d of %d %s%s, alphabetically. Narrow it with a keyword rather than assuming this '
                        . 'is the whole list.',
                    count($rows),
                    $total,
                    $label,
                    $scope,
                )
                : sprintf('All %d %s%s.', $total, $label, $scope),
        ];
    }
}
