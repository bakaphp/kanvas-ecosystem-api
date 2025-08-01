<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Observers;

use Kanvas\Intelligence\Agents\Models\Agent;

class AgentObserver
{
    public function saved(Agent $agent): void
    {
        $agent->clearLightHouseCache(withKanvasConfiguration: false);
    }
}
