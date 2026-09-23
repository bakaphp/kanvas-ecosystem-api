<?php

declare(strict_types=1);

namespace Kanvas\Event\Passes\Services;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Event\Participants\Models\ParticipantPassMotive;

class PassMotiveService
{
    public static function getMotive(
        Companies $company,
        Apps $app,
        string|int|null $motiveId = null,
        string|int|null $userId = null
    ): ParticipantPassMotive {
        $motive = is_numeric($motiveId)
            ? ParticipantPassMotive::fromCompany($company)->fromApp($app)->find((int) $motiveId)
            : null;

        // firstOrCreate only inserts the arrays it is handed: a fromCompany() scope filters the
        // lookup but never reaches the new row, which then falls through to CompaniesIdTrait.
        return $motive ?? ParticipantPassMotive::firstOrCreate(
            [
                'name' => 'Default',
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
            ],
            [
                'users_id' => $userId,
            ]
        );
    }
}
