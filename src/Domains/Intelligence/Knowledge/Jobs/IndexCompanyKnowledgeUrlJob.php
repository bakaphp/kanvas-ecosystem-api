<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeScope;
use Kanvas\Intelligence\Knowledge\Research\Services\WebsitePageCollector;
use Kanvas\Intelligence\Knowledge\Services\CompanyKnowledgeService;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeComponents;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * One page added as company knowledge: fetched through the same collector as the website
 * research (so the SSRF guard and the vendor fallbacks apply), indexed by URL so re-adding it
 * replaces in place. The outcome is written back to the company's URL list for the screen.
 */
final class IndexCompanyKnowledgeUrlJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public const string QUEUE = 'onboarding';

    public int $tries = 1;

    public function __construct(
        public readonly Apps $app,
        public readonly Companies $company,
        public readonly Users $user,
        public readonly string $url,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);
        $knowledge = new CompanyKnowledgeService();

        if (! KnowledgeComponents::knowledgeEnabled($this->app)) {
            $this->fail($knowledge, 'knowledge_disabled');

            return;
        }

        try {
            $pages = new WebsitePageCollector($this->app)->collectOne($this->url);
            $text = trim(implode("\n\n", array_column($pages->pages, 'content')));
            if ($text === '') {
                $this->fail($knowledge, 'page_empty');

                return;
            }

            $chunks = KnowledgeComponents::indexer($this->app)->indexDocument(
                KnowledgeScope::forTenant($this->app->getId(), $this->company->getId()),
                CompanyKnowledgeService::SOURCE_TYPE_URL,
                $this->url,
                $text,
            );

            $knowledge->recordUrl($this->company, $this->url, [
                'status' => 'indexed',
                'chunks' => $chunks,
                'indexed_at' => Carbon::now()->toIso8601String(),
                'error' => null,
            ]);
        } catch (Throwable $exception) {
            report($exception);
            // The message can name a resolved private address or a vendor's internals.
            $this->fail($knowledge, 'page_not_reachable');
        }
    }

    private function fail(CompanyKnowledgeService $knowledge, string $code): void
    {
        $knowledge->recordUrl($this->company, $this->url, ['status' => 'failed', 'error' => $code]);
    }
}
