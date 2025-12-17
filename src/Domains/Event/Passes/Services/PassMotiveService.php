<?php

declare(strict_types=1);

namespace Kanvas\Event\Passes\Services;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Event\Participants\Models\ParticipantPassMotive;

class PassMotiveService
{
    public static function getMotive(Companies $company, Apps $app, string|int|null $motiveId = null, string|int|null $userId = null): ParticipantPassMotive
    {
        $motive = ParticipantPassMotive::fromCompany($company)
            ->fromApp($app)
            ->find($motiveId);

        if (! $motive) {
            $motive = ParticipantPassMotive::fromCompany($company)
                ->fromApp($app)
                ->firstOrCreate([
                    'name' => 'Default',
                ], [
                    'users_id' => $userId,
                ]);
        }

        return $motive;
    }
}
