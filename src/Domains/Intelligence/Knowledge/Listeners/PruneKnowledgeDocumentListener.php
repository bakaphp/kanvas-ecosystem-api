<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Listeners;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Kanvas\Filesystem\Events\FileDeletedEvent;
use Kanvas\Intelligence\Knowledge\Actions\PruneKnowledgeDocumentAction;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeComponents;

final class PruneKnowledgeDocumentListener implements ShouldQueue
{
    use InteractsWithQueue;
    use KanvasJobsTrait;

    public string $queue = 'workflow';
    public int $tries = 3;

    public function shouldQueue(FileDeletedEvent $event): bool
    {
        return KnowledgeComponents::isAvailable($event->app);
    }

    public function handle(FileDeletedEvent $event): void
    {
        $this->overwriteAppService($event->app);

        new PruneKnowledgeDocumentAction($event->app, $event->companiesId, $event->uuid)->execute();
    }
}
