<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Baka\Enums\StateEnums;
use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Movipass\Enums\CustomFieldEnum;
use Kanvas\Enums\AppEnums;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Status\Models\Status;
use Kanvas\Inventory\Variants\Actions\AddToWarehouseAction;
use Kanvas\Inventory\Variants\DataTransferObject\VariantsWarehouses as VariantsWarehousesDto;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Kanvas\Inventory\Warehouses\Actions\CreateWarehouseAction;
use Kanvas\Inventory\Warehouses\DataTransferObject\Warehouses as WarehousesDto;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Regions\Models\Regions;
use Kanvas\Regions\Services\RegionResolutionService;
use Kanvas\Users\Models\Users;

/**
 * Moving a product to another company leaves its products_variants_warehouses rows
 * pointing at the previous owner's warehouse, which makes OrderItem::fromMultiple
 * reject the variant for having no warehouse in its own company.
 */
class RelinkVariantsToCompanyWarehouseAction
{
    public function __construct(
        protected readonly Apps $app,
        protected readonly Companies $company,
        protected readonly Users $user,
    ) {
    }

    public function execute(Collection $variants): int
    {
        if ($variants->isEmpty()) {
            return 0;
        }

        $warehouse = $this->resolveWarehouse();

        return $variants->reduce(
            fn (int $relinked, Variants $variant) => $relinked + (int) $this->relink($variant, $warehouse),
            0
        );
    }

    private function relink(Variants $variant, Warehouses $warehouse): bool
    {
        $ownLink = VariantsWarehouses::withTrashed()
            ->where('products_variants_id', $variant->getId())
            ->whereHas(
                'warehouse',
                fn ($query) => $query->where('companies_id', $this->company->getId())
            )
            ->orderBy('is_deleted')
            ->first();

        if ($ownLink !== null) {
            if (! $ownLink->is_deleted) {
                return false;
            }

            $ownLink->is_deleted = 0;
            $ownLink->save();

            return true;
        }

        $foreignLinks = VariantsWarehouses::where('products_variants_id', $variant->getId())
            ->orderBy('is_default', 'desc')
            ->get();

        if ($foreignLinks->isEmpty()) {
            new AddToWarehouseAction(
                $variant,
                $warehouse,
                VariantsWarehousesDto::viaRequest($variant, $warehouse, [
                    'status_id' => Status::getDefault($this->company, $this->app)?->getId(),
                ]),
            )->execute();

            return true;
        }

        // Move the row rather than recreating it so price, channel pricing and
        // history follow the variant to its new owner.
        $primary = $foreignLinks->shift();
        $primary->warehouses_id = $warehouse->getId();
        $primary->save();

        // Leftovers sit in warehouses of a company that no longer owns the variant.
        foreach ($foreignLinks as $stale) {
            $stale->is_deleted = 1;
            $stale->save();
        }

        return true;
    }

    private function resolveWarehouse(): Warehouses
    {
        $warehouse = Warehouses::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->orderBy('is_default', 'desc')
            ->first();

        if ($warehouse !== null) {
            return $warehouse;
        }

        return new CreateWarehouseAction(
            new WarehousesDto(
                company: $this->company,
                app: $this->app,
                user: $this->user,
                region: $this->resolveRegion(),
                name: StateEnums::DEFAULT_NAME->getValue(),
                location: StateEnums::DEFAULT_NAME->getValue(),
                is_default: true,
            ),
            $this->user,
        )->execute();
    }

    private function resolveRegion(): Regions
    {
        // Legacy key first: companies onboarded before the generic default_region_id
        // only carry movipass_region_id until the backfill command runs.
        $regionId = $this->company->get(CustomFieldEnum::COMPANY_REGION_ID->value);

        if (! empty($regionId)) {
            $region = Regions::query()
                ->fromApp($this->app)
                ->notDeleted()
                ->where('id', (int) $regionId)
                ->whereIn('companies_id', [AppEnums::GLOBAL_COMPANY_ID->getValue(), $this->company->getId()])
                ->first();

            if ($region !== null) {
                return $region;
            }
        }

        return new RegionResolutionService($this->app)->forCompany($this->company)
            ?? throw new ValidationException(
                "No region available for company '{$this->company->name}' — cannot provision its warehouse."
            );
    }
}
