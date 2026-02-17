<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Kanvas\Souk\Orders\Models\Order;

class SyncOrderProvidersAction
{
    public function __construct(
        protected Order $order
    ) {
    }

    /**
     * Sync provider companies to order_providers pivot table.
     * Extracts unique provider companies from order items (excluding B2B main company).
     */
    public function execute(): void
    {
        $platformCompanyId = $this->order->app->get('B2B_MAIN_COMPANY_ID');

        // Extract unique provider companies from order items
        $providerIds = $this->order->items()
            ->with('variant.product')
            ->get()
            ->pluck('variant.product.companies_id')
            ->filter(fn ($id) => $id && $id !== $platformCompanyId)
            ->unique()
            ->values();

        // Clear existing pivot records
        $this->order->providerCompanies()->detach();

        // Sync new provider companies
        if ($providerIds->isNotEmpty()) {
            $this->order->providerCompanies()->attach($providerIds->toArray());
        }
    }
}
