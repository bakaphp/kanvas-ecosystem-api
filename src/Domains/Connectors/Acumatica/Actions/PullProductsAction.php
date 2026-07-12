<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Acumatica\DataTransferObject\AcumaticaImportProduct;
use Kanvas\Connectors\Acumatica\SqlClient;
use Kanvas\Inventory\Importer\Actions\ProductImporterAction;
use Kanvas\Regions\Models\Regions as KanvasRegions;

class PullProductsAction
{
    public function __construct(
        protected Apps $app,
        protected Companies $company,
        protected UserInterface $user,
        protected KanvasRegions $region,
        protected int $acumaticaCompanyId,
        protected bool $stockItemsOnly = true,
        protected ?int $limit = null,
        protected ?Carbon $modifiedSince = null,
    ) {
    }

    public function execute(): int
    {
        return $this->processRows($this->fetchRows());
    }

    /**
     * @return array<int, array<array-key, mixed>>
     */
    protected function fetchRows(): array
    {
        $query = SqlClient::connection($this->app)
            ->table('InventoryItem')
            ->where('CompanyID', $this->acumaticaCompanyId);

        if ($this->stockItemsOnly) {
            $query->where('StkItem', 1);
        }

        if ($this->modifiedSince !== null) {
            $query->where('LastModifiedDateTime', '>', $this->modifiedSince);
        }

        if ($this->limit !== null) {
            $query->limit($this->limit);
        }

        return array_map(
            fn ($row): array => (array) $row,
            $query->get()->all()
        );
    }

    /**
     * @param array<int, array<array-key, mixed>> $rows
     */
    public function processRows(array $rows): int
    {
        $count = 0;

        foreach ($rows as $row) {
            $importer = AcumaticaImportProduct::from($row);

            if ($importer->sku === '') {
                continue;
            }

            new ProductImporterAction(
                $importer,
                $this->company,
                $this->user,
                $this->region,
                $this->app,
            )->execute();

            $count++;
        }

        return $count;
    }
}
