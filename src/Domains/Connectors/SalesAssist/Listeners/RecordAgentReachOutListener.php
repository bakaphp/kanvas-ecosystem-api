<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Listeners;

use Kanvas\Connectors\SalesAssist\Actions\RecordAgentReachOutAction;
use Kanvas\Intelligence\Agents\Events\AgentReachedOutToLeadEvent;

final class RecordAgentReachOutListener
{
    public function handle(AgentReachedOutToLeadEvent $event): void
    {
        new RecordAgentReachOutAction($event->lead, $event->result)->execute();
    }
}
