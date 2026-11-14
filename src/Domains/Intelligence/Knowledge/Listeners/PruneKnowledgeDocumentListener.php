<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Listeners;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Kanvas\Filesystem\Events\FileDeletedEvent;
use Kanvas\Intelligence\Knowledge\Actions\PruneKnowledgeDocumentAction;

final class PruneKnowledgeDocumentListener implements ShouldQueue
{
    use InteractsWithQueue;
    use KanvasJobsTrait;

    public string $queue = 'workflow';
    public int $tries = 3;

    public function handle(FileDeletedEvent $event): void
    {
        $this->overwriteAppService($event->filesystem->app);

        new PruneKnowledgeDocumentAction($event->filesystem)->execute();
    }
}
