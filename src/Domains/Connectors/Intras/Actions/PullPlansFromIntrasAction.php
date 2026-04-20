<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Actions;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Client;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;
use Kanvas\Connectors\Intras\Mappers\PlanMapper;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Warehouses\Models\Warehouses;

class PullPlansFromIntrasAction
{
    public function __construct(
        protected AppInterface $app,
        protected Companies $company,
        protected UserInterface $user,
        protected ?int $agencyId = null
    ) {
    }

    public function execute(): array
    {
        $client = new Client($this->app);
        $counts = ['plans' => 0, 'variants' => 0];

        $query = $client->table('plans')
            ->where('is_deleted', 0);

        if ($this->agencyId !== null) {
            $query->where('agencies_id', $this->agencyId);
        }

        $plans = $query->get();

        $agencyName = '';
        if ($this->agencyId !== null) {
            $agency = $client->table('agencies')->where('id', $this->agencyId)->first();
            $agencyName = $agency ? trim($agency->name) : '';
        }

        $warehouse = Warehouses::where('apps_id', $this->app->getId())
            ->fromCompany($this->company)
            ->where('is_default', 1)
            ->first();

        foreach ($plans as $plan) {
            $mapped = PlanMapper::planToProduct($plan, $agencyName);
            $slug = Str::slug($mapped['name'] . '-' . $plan->id);

            $product = Products::firstOrCreate([
                'slug' => $slug,
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->company->getId(),
            ], [
                'users_id' => $this->user->getId(),
                'name' => $mapped['name'],
                'description' => $mapped['description'],
                'is_published' => true,
            ]);

            $product->set(CustomFieldEnum::INTRAS_EVENT_ID->value, $plan->id);

            foreach ($mapped['custom_fields'] as $key => $value) {
                $product->set($key, $value);
            }

            $counts['plans']++;

            $this->pullPlanDetails($client, $plan->id, $product, $warehouse, $counts);
        }

        return $counts;
    }

    protected function pullPlanDetails(
        Client $client,
        int $planId,
        Products $product,
        ?Warehouses $warehouse,
        array &$counts
    ): void {
        $details = $client->table('plans_details')
            ->leftJoin('currencies', 'plans_details.currencies_id', '=', 'currencies.id')
            ->where('plans_details.is_deleted', 0)
            ->where('plans_details.is_deprecated', 0)
            ->where('plans_details.plans_id', $planId)
            ->select('plans_details.*', 'currencies.name as currency_name')
            ->get();

        foreach ($details as $detail) {
            $mapped = PlanMapper::planDetailToVariant($detail);
            $variantSlug = Str::slug($product->slug . '-' . $mapped['name'] . '-' . $detail->id);
            $sku = Str::slug($product->name . '-' . $mapped['name']);

            $variant = Variants::firstOrCreate([
                'slug' => $variantSlug,
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->company->getId(),
                'products_id' => $product->getId(),
            ], [
                'users_id' => $this->user->getId(),
                'name' => $mapped['name'],
                'sku' => $sku,
                'description' => $mapped['name'] . ' - ' . $mapped['price'] . ' per ticket',
            ]);

            foreach ($mapped['custom_fields'] as $key => $value) {
                $variant->set($key, $value);
            }

            if ($warehouse) {
                $variant->variantWarehouses()->firstOrCreate([
                    'warehouses_id' => $warehouse->getId(),
                ], [
                    'quantity' => $mapped['custom_fields']['max_tickets'] ?? 0,
                    'price' => $mapped['price'],
                    'sku' => $sku,
                ]);
            }

            $counts['variants']++;
        }
    }
}
