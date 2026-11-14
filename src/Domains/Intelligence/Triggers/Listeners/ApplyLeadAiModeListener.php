<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Triggers\Listeners;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Triggers\Actions\TriggerLeadIntelligenceAction;
use Kanvas\Intelligence\Triggers\Events\LeadAiTriggeredEvent;

/**
 * A lead's AI mode only means something where an agent works the leads, so a company no active
 * agent serves is left alone.
 */
final class ApplyLeadAiModeListener implements ShouldQueue
{
    use InteractsWithQueue;
    use KanvasJobsTrait;

    public string $queue = 'workflow';
    public int $tries = 3;

    public function shouldQueue(LeadAiTriggeredEvent $event): bool
    {
        return Agent::query()
            ->servingCompany($event->lead->app, $event->lead->company)
            ->exists();
    }

    public function handle(LeadAiTriggeredEvent $event): void
    {
        $this->overwriteAppService($event->lead->app);

        new TriggerLeadIntelligenceAction($event->lead, $event->triggerType)->execute();
    }
}
