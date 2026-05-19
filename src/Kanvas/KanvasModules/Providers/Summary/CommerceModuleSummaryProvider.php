<?php

declare(strict_types=1);

namespace Kanvas\KanvasModules\Providers\Summary;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\KanvasModules\Contracts\KanvasModuleSummaryProvider;
use Kanvas\Souk\Orders\Models\Order;
use Override;

class CommerceModuleSummaryProvider implements KanvasModuleSummaryProvider
{
    #[Override]
    public function summary(Companies $company, Apps $app): array
    {
        $orders = Order::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('is_deleted', 0)
            ->count();

        $products = Products::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('is_deleted', 0)
            ->count();

        return [
            'orders' => $orders,
            'products' => $products,
        ];
    }
}
