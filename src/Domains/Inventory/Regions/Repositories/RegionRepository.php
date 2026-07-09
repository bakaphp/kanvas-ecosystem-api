<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Regions\Repositories;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Traits\SearchableTrait;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\Inventory\Regions\Models\Regions as RegionModel;

class RegionRepository
{
    use SearchableTrait;

    public static function getModel(): RegionModel
    {
        return new RegionModel();
    }

    /**
     * Resolve a region by id within the company OR an app-global region (companies_id = 0).
     * Unlike the Souk `fromCompanyOrGlobal` scope, global inclusion here is unconditional —
     * it is not gated by ALLOW_CROSS_COMPANY_VARIANTS, since integration setup legitimately
     * uses shared global regions regardless of the product-variants flag.
     */
    public static function getByIdOrGlobal(int $id, CompanyInterface $company, ?AppInterface $app = null): RegionModel
    {
        try {
            return self::getModel()
                ->fromApp($app)
                ->notDeleted()
                ->where('id', $id)
                ->where(
                    fn ($query) => $query
                        ->where('companies_id', $company->getId())
                        ->orWhere('companies_id', 0)
                )
                ->firstOrFail();
        } catch (ModelNotFoundException $e) {
            throw new ExceptionsModelNotFoundException($e->getMessage());
        }
    }

    public static function getDefault(CompanyInterface $company): RegionModel
    {
        return self::getModel()
            ->where('is_default', 1)
            ->fromCompanyOrGlobal($company)
            ->orderByRaw('CASE WHEN companies_id = 0 THEN 1 ELSE 0 END ASC')
            ->firstOrFail();
    }
}
