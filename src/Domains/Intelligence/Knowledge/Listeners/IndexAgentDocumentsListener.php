<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Listeners;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Kanvas\Filesystem\Events\FileAttachedToEntityEvent;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Knowledge\Actions\IndexAgentDocumentsAction;

final class IndexAgentDocumentsListener implements ShouldQueue
{
    use InteractsWithQueue;
    use KanvasJobsTrait;

    public string $queue = 'workflow';
    public int $tries = 3;

    public function shouldQueue(FileAttachedToEntityEvent $event): bool
    {
        return $event->entity instanceof Agent;
    }

    public function handle(FileAttachedToEntityEvent $event): void
    {
        /** @var Agent $agent */
        $agent = $event->entity;

        $this->overwriteAppService($agent->app);

        new IndexAgentDocumentsAction($agent)->execute();
    }
}
