<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Handlers;

use Baka\Contracts\BaseIntegration;
use Baka\Support\Str;
use Exception;
use Kanvas\Connectors\NetSuite\DataTransferObject\NetSuite as NetSuiteDto;
use Kanvas\Connectors\NetSuite\Importers\NetSuiteCustomersImporter;
use Kanvas\Connectors\NetSuite\Importers\NetSuiteProductsImporter;
use Kanvas\Connectors\NetSuite\Importers\NetSuiteStockImporter;
use Kanvas\Connectors\NetSuite\Services\NetSuiteCustomerService;
use Kanvas\Connectors\NetSuite\Services\NetSuiteServices;
use Kanvas\Workflow\Integrations\Imports\DataTransferObject\Importable;
use Kanvas\Workflow\Integrations\Imports\DataTransferObject\ImportOption;
use Kanvas\Workflow\Integrations\Imports\Enums\ImportOptionTypeEnum;
use Override;

class NetSuiteHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $netSuiteDto = new NetSuiteDto(
            company: $this->company,
            app: $this->app,
            account: $this->data['account'],
            consumerKey: $this->data['consumerKey'],
            consumerSecret: $this->data['consumerSecret'],
            token: $this->data['token'],
            tokenSecret: $this->data['tokenSecret'],
        );

        NetSuiteServices::setup($netSuiteDto);
        $client = new NetSuiteServices($this->app, $this->company);
        $customer = new NetSuiteCustomerService(
            app: $this->app,
            company: $this->company,
        );

        try {
            $customer->getCustomerById('1');
        } catch (Exception $e) {
            if (Str::contains($e->getMessage(), 'Error retrieving customer:')) {
                return true;
            }

            return false;
        }

        return true;
    }

    #[Override]
    public function importables(): array
    {
        $savedSearch = new ImportOption(
            name: 'saved_search_id',
            type: ImportOptionTypeEnum::STRING,
            label: 'Saved search',
        );

        return [
            new Importable(
                entity: 'customers',
                label: 'Customers',
                importer: NetSuiteCustomersImporter::class,
                options: [$savedSearch],
            ),
            new Importable(
                entity: 'products',
                label: 'Productos',
                importer: NetSuiteProductsImporter::class,
                options: [
                    new ImportOption(
                        name: 'location_ids',
                        type: ImportOptionTypeEnum::CHOICES,
                        label: 'Bodegas',
                        required: true,
                        multiple: true,
                    ),
                    $savedSearch,
                ],
            ),
            new Importable(
                entity: 'stock',
                label: 'Stock',
                importer: NetSuiteStockImporter::class,
                options: [
                    $savedSearch,
                    new ImportOption(
                        name: 'create_missing',
                        type: ImportOptionTypeEnum::BOOLEAN,
                        label: 'Crear filas que falten',
                    ),
                ],
            ),
        ];
    }
}
