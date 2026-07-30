<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Exporters;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Neuron\Exporters\Traits\ReadsExportFilters;
use Kanvas\Inventory\Products\Models\Products;
use Override;

class ProductsRecordExporter implements RecordExporterInterface
{
    use ReadsExportFilters;

    #[Override]
    public function type(): string
    {
        return 'products';
    }

    #[Override]
    public function filtersHint(): string
    {
        return 'optional search (name), is_published (bool), only_in_stock (bool)';
    }

    #[Override]
    public function headers(): array
    {
        return ['Name', 'Slug', 'Published', 'Total Stock'];
    }

    #[Override]
    public function rows(Apps $app, Companies $company, array $filters): array
    {
        $search = $this->filterString($filters, 'search');
        $onlyInStock = $this->filterBool($filters, 'only_in_stock', false);
        $publishedFilter = array_key_exists('is_published', $filters)
            ? $this->filterBool($filters, 'is_published', true)
            : null;

        $products = Products::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->when($search !== null, fn ($q) => $q->where('name', 'like', '%' . $search . '%'))
            ->when($publishedFilter !== null, fn ($q) => $q->where('is_published', $publishedFilter ? 1 : 0))
            ->with('variants')
            ->orderByDesc('id')
            ->limit(RecordExporterRegistry::MAX_ROWS)
            ->get();

        $rows = [];
        foreach ($products as $product) {
            $stock = (int) $product->variants->sum(fn ($variant): int => (int) $variant->getTotalQuantity());
            if ($onlyInStock && $stock <= 0) {
                continue;
            }

            $rows[] = [$product->name, $product->slug, $product->is_published ? 'Yes' : 'No', $stock];
        }

        return $rows;
    }
}
