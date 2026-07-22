<?php

declare(strict_types=1);

namespace App\Console\Commands\Movipass;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Movipass\Actions\RelinkVariantsToCompanyWarehouseAction;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Throwable;

use function Laravel\Prompts\info;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

class RelinkCorporateVariantWarehousesCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas-movipass:relink-corporate-variant-warehouses
        {app_id : The app the products belong to}
        {--company_id= : Only repair this company}
        {--dry-run : List what would be relinked without making changes}';

    protected $description = 'Repoint variants whose product changed company (corporate migration) to a warehouse of their own company, so they can be ordered again.';

    public function handle(): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $companyIds = Products::query()
            ->where('apps_id', $app->getId())
            ->when(
                $this->option('company_id'),
                fn ($query, $companyId) => $query->where('companies_id', (int) $companyId)
            )
            ->distinct()
            ->pluck('companies_id');

        $totalBroken = 0;
        $totalRelinked = 0;

        foreach ($companyIds as $companyId) {
            $variants = $this->variantsWithoutOwnWarehouse($app, (int) $companyId);

            if ($variants->isEmpty()) {
                continue;
            }

            $totalBroken += $variants->count();

            /** @var Companies $company */
            $company = Companies::getById((int) $companyId);

            info(sprintf('%s (ID %d) — %d variant(s) without a warehouse of their own company', $company->name, $company->getId(), $variants->count()));
            table(
                ['variant_id', 'sku', 'name'],
                $variants->take(10)->map(fn (Variants $variant) => [
                    $variant->getId(),
                    $variant->sku,
                    $variant->name,
                ])->toArray()
            );

            if ($this->option('dry-run')) {
                continue;
            }

            $owner = $company->user;

            if ($owner === null) {
                warning("Company {$company->getId()} has no owner user — skipped.");

                continue;
            }

            try {
                $totalRelinked += new RelinkVariantsToCompanyWarehouseAction(
                    app: $app,
                    company: $company,
                    user: $owner,
                )->execute($variants);
            } catch (Throwable $e) {
                warning("Company {$company->getId()} failed: {$e->getMessage()}");
            }
        }

        if ($totalBroken === 0) {
            info('No variants found without a warehouse of their own company. Nothing to do.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            info("Dry run — {$totalBroken} variant(s) would be relinked.");

            return self::SUCCESS;
        }

        info("Relinked {$totalRelinked} of {$totalBroken} variant(s).");

        return self::SUCCESS;
    }

    private function variantsWithoutOwnWarehouse(Apps $app, int $companyId): Collection
    {
        return Variants::query()
            ->whereHas(
                'product',
                fn ($query) => $query->where('apps_id', $app->getId())->where('companies_id', $companyId)
            )
            ->whereDoesntHave(
                'variantWarehouses.warehouse',
                fn ($query) => $query->where('companies_id', $companyId)
            )
            ->get();
    }
}
