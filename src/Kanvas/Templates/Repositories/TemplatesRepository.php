<?php

declare(strict_types=1);

namespace Kanvas\Templates\Repositories;

use Kanvas\Templates\Models\Templates;

class TemplatesRepository
{
    /**
     * Retrieve email template by name.
     *
     * @param $name
     *
     * @return Templates
     */
    public static function getByName(string $name): Templates
    {
        // $companyId = userData->currentCompanyId() ?? 0;

        return Templates::fromApp()
                            ->notDeleted()
                            // ->where('companies_id',$companyId)
                            ->where('name', $name)
                            ->orderBy('id', 'desc')
                            ->firstOrFail();
    }
}
