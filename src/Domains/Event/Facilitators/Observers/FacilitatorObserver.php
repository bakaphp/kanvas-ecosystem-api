<?php

declare(strict_types=1);

namespace Kanvas\Event\Facilitators\Observers;

use Kanvas\Event\Facilitators\Models\Facilitator;

class FacilitatorObserver
{
    public function updating(Facilitator $facilitator): void
    {
        $facilitator->clearLightHouseCache(withKanvasConfiguration: false);
    }
}
