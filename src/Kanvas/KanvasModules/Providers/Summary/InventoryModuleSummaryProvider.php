<?php

declare(strict_types=1);

namespace Kanvas\KanvasModules\Providers\Summary;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\KanvasModules\Contracts\KanvasModuleSummaryProvider;
use Override;

class InventoryModuleSummaryProvider implements KanvasModuleSummaryProvider
{
    #[Override]
    public function summary(Companies $company, Apps $app): array
    {
        $products = Products::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('is_deleted', 0)
            ->count();

        return [
            'products' => $products,
        ];
    }
}
