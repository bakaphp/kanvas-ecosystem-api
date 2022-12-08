<?php
declare(strict_types=1);
namespace Kanvas\Inventory\Attributes\Repositories;

use Kanvas\Inventory\Attributes\Models\Attributes;

class AttributesRepository
{
    /**
     * getById
     *
     * @param  int $id
     * @param  int $companiesId
     * @return Attributes
     */
    public static function getById(int $id, ?int $companiesId = null):Attributes
    {
        $companiesId = $companiesId ?? auth()->user()->default_company;
        return Attributes::where('companies_id', $companiesId)
            ->findOrFail($id);
    }
}
