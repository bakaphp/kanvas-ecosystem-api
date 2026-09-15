<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Channels\Repositories;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Traits\SearchableTrait;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\Inventory\Channels\Models\Channels;

class ChannelRepository
{
    use SearchableTrait;

    public static function getModel(): Channels
    {
        return new Channels();
    }

    /**
     * Resolve a channel by id within the company OR an app-global channel (companies_id = 0).
     * Mirrors RegionRepository::getByIdOrGlobal() — global inclusion here is unconditional, not
     * gated by ALLOW_CROSS_COMPANY_VARIANTS, so a single app-wide channel (e.g. "popular") can be
     * referenced by any company's variant/product import regardless of that setting.
     */
    public static function getByIdOrGlobal(int $id, CompanyInterface $company, ?AppInterface $app = null): Channels
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
}
