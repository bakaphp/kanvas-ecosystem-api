<?php

declare(strict_types=1);

namespace Kanvas\Event\Participants\Actions;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Event\Participants\Models\Participant;
use Kanvas\Event\Themes\Models\ThemeArea;
use Kanvas\Guild\Customers\Models\People;

class SyncPeopleWithParticipantAction
{
    public function __construct(
        protected People $people,
        protected UserInterface $user
    ) {
    }

    public function execute(): Participant
    {
        $themeArea = ThemeArea::fromApp($this->people->app)
            ->fromCompany($this->people->company)
            ->where('name', 'Virtual')->firstOrFail();

        return Participant::firstOrCreate([
            'people_id' => $this->people->getId(),
            'apps_id' => $this->people->apps_id,
            'companies_id' => $this->people->companies_id,
        ], [
            'users_id' => $this->user->getId(),
            'theme_area_id' => $themeArea->getId(),
            'participant_status_id' => 1,
        ]);
    }
}
