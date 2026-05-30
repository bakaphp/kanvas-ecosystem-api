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
     * user_company_id is scanned at both metadata paths because different order flows
     * persist it at different depths (cart-wallet at top level, Movipass-style under data.).
     */
    public function execute(): void
    {
        $platformCompanyId = $this->order->app->get('B2B_MAIN_COMPANY_ID');
        $orderCompanyId = (int) $this->order->companies_id;

        $providerIds = $this->order->items()
            ->with('variant.product')
            ->get()
            ->pluck('variant.product.companies_id');

        $metadata = $this->order->metadata ?? [];
        $userCompanyId = $metadata['user_company_id']
            ?? ($metadata['data']['user_company_id'] ?? null);

        if ($userCompanyId !== null) {
            $providerIds = $providerIds->push($userCompanyId);
        }

        $providerIds = $providerIds
            ->map(fn ($id) => (int) $id)
            ->filter(
                fn (int $id) => $id > 0
                && $id !== $orderCompanyId
                && ($platformCompanyId === null || $id !== (int) $platformCompanyId)
            )
            ->unique()
            ->values();

        $this->order->providerCompanies()->detach();

        if ($providerIds->isNotEmpty()) {
            $this->order->providerCompanies()->attach($providerIds->toArray());
        }
    }
}
