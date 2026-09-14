<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Actions;

use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Connectors\Acumatica\SqlClient;
use Kanvas\Inventory\Warehouses\Actions\CreateWarehouseAction;
use Kanvas\Inventory\Warehouses\DataTransferObject\Warehouses as WarehousesDto;
use Kanvas\Regions\Models\Regions as KanvasRegions;

class PullWarehousesAction
{
    public function __construct(
        protected Apps $app,
        protected Companies $company,
        protected UserInterface $user,
        protected KanvasRegions $region,
        protected int $acumaticaCompanyId,
        protected ?int $limit = null,
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
            ->table('INSite')
            ->where('CompanyID', $this->acumaticaCompanyId)
            ->where('Active', 1);

        if ($this->limit !== null) {
            $query->limit($this->limit);
        }

        return array_map(fn ($row): array => (array) $row, $query->get()->all());
    }

    /**
     * @param array<int, array<array-key, mixed>> $rows
     */
    public function processRows(array $rows): int
    {
        $count = 0;

        foreach ($rows as $row) {
            $code = trim((string) ($row['SiteCD'] ?? ''));

            if ($code === '') {
                continue;
            }

            $warehouse = new CreateWarehouseAction(
                new WarehousesDto(
                    company: $this->company,
                    app: $this->app,
                    user: $this->user,
                    region: $this->region,
                    name: $code,
                    location: Str::trimToNull((string) ($row['Descr'] ?? '')),
                ),
                $this->user,
            )->execute();

            $warehouse->set(CustomFieldEnum::WAREHOUSE_ID->value, (string) ($row['SiteID'] ?? ''));

            $count++;
        }

        return $count;
    }
}
