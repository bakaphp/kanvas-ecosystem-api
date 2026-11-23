<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\NetSuite\Mutations;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Actions\ImportNetSuiteLocationsAction;
use Kanvas\Connectors\NetSuite\Enums\ImportEntityEnum;
use Kanvas\Connectors\NetSuite\Jobs\ImportNetSuiteDataJob;
use Kanvas\Connectors\NetSuite\Services\NetSuiteImportStatusService;
use Kanvas\Connectors\NetSuite\Services\NetSuiteLocationWarehouseService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Warehouses\Models\Warehouses;

class NetSuiteImportMutation
{
    use ResolvesActingContext;

    public function import(mixed $root, array $request): array
    {
        $ctx = $this->actingContext();
        $input = $request['input'];
        $entity = ImportEntityEnum::from($input['entity']);

        /** @var Companies $company */
        $company = $ctx->company;
        $status = new NetSuiteImportStatusService($company, $entity);

        if ($status->isInProgress()) {
            throw new ValidationException('A NetSuite ' . $entity->value . ' import is already in progress.');
        }

        $locationIds = array_values(array_unique(array_map('strval', $input['location_ids'] ?? [])));

        if ($locationIds !== [] && $entity !== ImportEntityEnum::PRODUCTS) {
            throw new ValidationException('location_ids only applies to a PRODUCTS import.');
        }

        // Mapping first: an unknown location must be refused before anything is queued.
        if ($locationIds !== []) {
            new ImportNetSuiteLocationsAction($ctx->app, $company, $ctx->user)->ensureMapped($locationIds);
        } elseif ($entity->readsLocations()) {
            new NetSuiteLocationWarehouseService($ctx->app, $company)->assertMapped();
        }

        $savedSearchId = $entity->resolveSavedSearchId(
            $ctx->app,
            $company,
            isset($input['saved_search_id']) ? (string) $input['saved_search_id'] : null
        );

        $queued = $status->queue($savedSearchId, $locationIds);

        ImportNetSuiteDataJob::dispatch(
            app: $ctx->app,
            company: $company,
            user: $ctx->user,
            entity: $entity,
            savedSearchId: $savedSearchId,
            createMissing: (bool) ($input['create_missing'] ?? false),
            locationIds: $locationIds,
        );

        return $queued;
    }

    /**
     * @return list<Warehouses>
     */
    public function importLocations(mixed $root, array $request): array
    {
        $ctx = $this->actingContext();
        $input = $request['input'];

        /** @var Companies $company */
        $company = $ctx->company;

        return new ImportNetSuiteLocationsAction($ctx->app, $company, $ctx->user)->execute(
            (string) $input['default_location_id'],
            array_map('strval', $input['location_ids'] ?? [])
        );
    }
}
