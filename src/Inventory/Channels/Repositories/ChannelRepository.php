<?php
declare(strict_types=1);
namespace Kanvas\Inventory\Channels\Repositories;

use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Apps\Models\Apps;

class ChannelRepository
{
    /**
     * getById
     *
     * @param  int $id
     * @param  int $companiesId
     * @return Channels
     */
    public static function getById(int $id, ?int $companiesId = null): Channels
    {
        return Channels::where('companies_id', $companiesId)
            ->where('apps_id', app(Apps::class)->id)
            ->findOrFail($id);
    }
}
