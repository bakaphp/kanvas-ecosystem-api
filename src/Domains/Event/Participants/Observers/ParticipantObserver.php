<?php

declare(strict_types=1);

namespace Kanvas\Event\Participants\Observers;

use Baka\Support\Str;
use Kanvas\Event\Participants\Models\Participant;

class ParticipantObserver
{
    public function creating(Participant $participant): void
    {
        $this->syncSlug($participant);
    }

    public function updating(Participant $participant): void
    {
        $this->syncSlug($participant);
        $participant->clearLightHouseCache(withKanvasConfiguration: false);
    }

    protected function syncSlug(Participant $participant): void
    {
        if (! empty($participant->slug)) {
            return;
        }

        $people = $participant->people;
        if ($people === null) {
            return;
        }

        $participant->slug = Str::slug("{$people->name} {$people->id}");
    }
}
