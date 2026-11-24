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
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeScope;
use Kanvas\Intelligence\Knowledge\Services\CompanyKnowledgeService;
use Kanvas\Intelligence\Knowledge\Services\EntityFilesIndexer;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeComponents;
use Kanvas\Users\Models\Users;

/**
 * Indexes freshly uploaded company knowledge documents company-wide. Only files that carry the
 * knowledge marker on this company are taken, whatever ids were handed in.
 */
final class IndexCompanyKnowledgeFilesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public const string QUEUE = 'onboarding';

    /**
     * @param list<int> $filesystemIds
     */
    public function __construct(
        public readonly Apps $app,
        public readonly Companies $company,
        public readonly Users $user,
        public readonly array $filesystemIds,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        if (! KnowledgeComponents::knowledgeEnabled($this->app)) {
            return;
        }

        $files = new CompanyKnowledgeService()->documents($this->company)
            ->filter(fn (Filesystem $file): bool => in_array((int) $file->getId(), $this->filesystemIds, true));

        $indexer = new EntityFilesIndexer();
        $indexer->index(
            $this->app,
            KnowledgeScope::forTenant($this->app->getId(), $this->company->getId()),
            CompanyKnowledgeService::SOURCE_TYPE_DOCUMENT,
            $indexer->indexableAmong($files),
        );
    }
}
