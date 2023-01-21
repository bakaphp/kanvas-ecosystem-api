<?php
declare(strict_types=1);

namespace Kanvas\Inventory\Channels\Repositories;

use Baka\Contracts\CompanyInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Channels\Models\Channels;

class ChannelRepository
{
    /**
     * getById.
     *
     * @param  int $id
     * @param  CompanyInterface|null $company
     *
     * @return Categories
     */
    public static function getById(int $id, ?CompanyInterface $company = null) : Channels
    {
        $company = $company ?? auth()->user()->getCurrentCompany();
        return Channels::where('companies_id', $company->getId())
            ->where('apps_id', app(Apps::class)->id)
            ->findOrFail($id);
    }
}
