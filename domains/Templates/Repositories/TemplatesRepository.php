<?php

declare(strict_types=1);

namespace Kanvas\Templates\Repositories;

use Kanvas\Templates\Models\Templates;
use Kanvas\Apps\Apps\Models\Apps;

class TemplatesRepository
{
    /**
     * Retrieve email template by name.
     *
     * @param $name
     *
     * @return Templates
     */
    public static function getByName(string $name) : Templates
    {
        $appId = app(Apps::class)->getKey();
        // $userData = app('userData');

        // $companyId = userData->currentCompanyId() ?? 0;

        $emailTemplate =  Templates::where('apps_id', $appId)
                            // ->where('companies_id',$companyId)
                            ->where('name', $name)
                            ->where('is_deleted',0)
                            ->orderBy('id','desc')
                            ->firstOrFail();

        return $emailTemplate;
    }
}
