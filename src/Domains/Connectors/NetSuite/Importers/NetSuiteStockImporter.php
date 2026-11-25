<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Importers;

use Illuminate\Support\Arr;
use Kanvas\Connectors\NetSuite\Actions\PullNetSuiteProductStockAction;
use Kanvas\Connectors\NetSuite\Enums\ImportEntityEnum;
use Kanvas\Connectors\NetSuite\Services\NetSuiteLocationWarehouseService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Integrations\Imports\Importers\SingleRunImporter;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Override;

class NetSuiteStockImporter extends SingleRunImporter
{
    protected PullNetSuiteProductStockAction $stock;

    public function __construct(IntegrationsCompany $integrationCompany, Users $user)
    {
        parent::__construct($integrationCompany, $user);

        $this->stock = new PullNetSuiteProductStockAction($this->app(), $this->company(), $user);
    }

    /**
     * Without a mapped warehouse every search would have nowhere to write.
     */
    #[Override]
    public function prepare(array $options): array
    {
        $savedSearchId = ImportEntityEnum::STOCK->resolveSavedSearchId(
            $this->app(),
            $this->company(),
            $options['saved_search_id'] ?? null
        );

        new NetSuiteLocationWarehouseService($this->app(), $this->company())->assertMapped();

        return [...$options, 'saved_search_id' => $savedSearchId];
    }

    /**
     * Totals only: the stock action's per-location SKU lists run to the size of the catalog, and the
     * summary is stored on the run.
     */
    #[Override]
    public function run(array $options): array
    {
        $result = $this->stock->execute(
            $options['saved_search_id'],
            createMissing: (bool) ($options['create_missing'] ?? false)
        );

        if (isset($result['error'])) {
            throw new ValidationException($result['error']);
        }

        $locationErrors = array_filter($result['locations'] ?? [], fn (array $location) => isset($location['error']));

        return [
            ...Arr::only($result, [
                'processed',
                'created',
                'not_found_in_kanvas',
                'not_found_in_netsuite',
                'not_in_warehouse',
                'update_failed',
            ]),
            'location_errors' => array_values(array_map(
                fn (array $location) => Arr::only($location, ['warehouses_id', 'location_id', 'error']),
                $locationErrors
            )),
        ];
    }
}
