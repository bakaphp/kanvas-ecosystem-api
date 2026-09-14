<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Yusen\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\Yusen\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Override;

/**
 * Yusen pushes to us — there are no credentials to validate, so setup is only about which
 * warehouse the discrepancy report compares their count against, and how strict it is.
 */
class YusenHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        if (isset($this->data['primary_warehouse_id'])) {
            $primaryId = (int) $this->data['primary_warehouse_id'];
            $this->assertWarehouseExists($primaryId);
            $this->company->set(ConfigurationEnum::PRIMARY_WAREHOUSE_ID->value, $primaryId);
        }

        if (isset($this->data['netsuite_saved_search_id'])) {
            $this->company->set(
                ConfigurationEnum::NETSUITE_SAVED_SEARCH_ID->value,
                (string) $this->data['netsuite_saved_search_id']
            );
        }

        if (isset($this->data['netsuite_location_id'])) {
            $this->company->set(
                ConfigurationEnum::NETSUITE_LOCATION_ID->value,
                (string) $this->data['netsuite_location_id']
            );
        }

        if (isset($this->data['match_field'])) {
            $matchField = (string) $this->data['match_field'];

            if (! in_array($matchField, ['barcode', 'sku'], true)) {
                throw new ValidationException('match_field must be either "barcode" or "sku"');
            }

            $this->company->set(ConfigurationEnum::MATCH_FIELD->value, $matchField);
        }

        if (isset($this->data['quantity_tolerance'])) {
            $this->company->set(
                ConfigurationEnum::QUANTITY_TOLERANCE->value,
                (float) $this->data['quantity_tolerance']
            );
        }

        if (isset($this->data['reconcile_with_netsuite'])) {
            $this->company->set(
                ConfigurationEnum::RECONCILE_WITH_NETSUITE->value,
                (bool) $this->data['reconcile_with_netsuite']
            );
        }

        return true;
    }

    private function assertWarehouseExists(int $warehouseId): void
    {
        $exists = Warehouses::query()
            ->where('id', $warehouseId)
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->exists();

        if (! $exists) {
            throw new ValidationException(
                'Warehouse ' . $warehouseId . ' does not exist for this company'
            );
        }
    }
}
