<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeScope;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeComponents;

/**
 * Drops one company knowledge source (a document by uuid, a page by URL) from the index.
 */
final class PruneCompanyKnowledgeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public const string QUEUE = 'onboarding';

    public function __construct(
        public readonly Apps $app,
        public readonly Companies $company,
        public readonly string $sourceType,
        public readonly string $sourceName,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        if (! KnowledgeComponents::knowledgeEnabled($this->app)) {
            return;
        }

        KnowledgeComponents::store($this->app)->deleteBySource(
            KnowledgeScope::forTenant($this->app->getId(), $this->company->getId()),
            $this->sourceType,
            $this->sourceName,
        );
    }
}
