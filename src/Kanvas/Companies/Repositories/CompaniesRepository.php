<?php

declare(strict_types=1);

namespace Kanvas\Companies\Repositories;

use Kanvas\Companies\Models\Companies;

class CompaniesRepository
{
    /**
     * Get company by Id.
     *
     * @param int $id
     *
     * @return Companies
     *
     * @throws Exception
     */
    public static function getById(int $id) : Companies
    {
        return Companies::where('id', $id)->firstOrFail();
    }
}
