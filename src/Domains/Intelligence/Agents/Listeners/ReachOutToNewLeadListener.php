<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Listeners;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Kanvas\Guild\Leads\Events\LeadCreatedEvent;
use Kanvas\Intelligence\Agents\Actions\Outreach\ReachOutToNewLeadAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Triggers\Enums\TriggersEnum;
use Kanvas\Intelligence\Triggers\Events\LeadAiTriggeredEvent;

final class ReachOutToNewLeadListener implements ShouldQueue
{
    use InteractsWithQueue;
    use KanvasJobsTrait;

    // A reach-out is a whole agent turn, which the workflow workers are sized for.
    public string $queue = 'workflow';

    // A retry after a partial send would message the customer twice.
    public int $tries = 1;

    public function shouldQueue(LeadCreatedEvent $event): bool
    {
        return Agent::forNewLeads($event->lead->app, $event->lead->company) !== null;
    }

    public function handle(LeadCreatedEvent $event): void
    {
        $this->overwriteAppService($event->lead->app);

        $lead = $event->lead;
        $agent = Agent::forNewLeads($lead->app, $lead->company);

        // The switch can be turned off, or the agent paused, while the reach-out waits on the queue.
        // It was going to give the new lead its AI mode, so that part is handed back.
        if ($agent === null) {
            LeadAiTriggeredEvent::dispatch($lead, TriggersEnum::NEW_LEAD->value);

            return;
        }

        new ReachOutToNewLeadAction($lead, $agent)->execute();
    }
}
