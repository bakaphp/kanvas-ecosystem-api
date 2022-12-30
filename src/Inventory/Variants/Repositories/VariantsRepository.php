<?php
declare(strict_types=1);
namespace Kanvas\Inventory\Variants\Repositories;

use Kanvas\Inventory\Variants\Models\Variants;

class VariantsRepository
{
    /**
     * getById
     *
     * @param  int $id
     * @param  int $companiesId
     * @return Variants
     */
    public static function getById(int $id, ?int $companiesId = null): Variants
    {
        $companiesId = $companiesId ?? auth()->user()->default_company;
        return Variants::where('companies_id', $companiesId)->findOrFail($id);
    }
}
