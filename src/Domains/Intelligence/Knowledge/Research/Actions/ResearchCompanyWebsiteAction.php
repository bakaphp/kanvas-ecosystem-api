<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Research\Actions;

use Baka\Http\Exceptions\SsrfException;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Onboarding\Enums\ResearchStatusEnum;
use Kanvas\Companies\Onboarding\Models\CompanyOnboarding;
use Kanvas\Companies\Onboarding\Support\WebsiteUrl;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeScope;
use Kanvas\Intelligence\Knowledge\Research\Agents\CompanyResearchAgent;
use Kanvas\Intelligence\Knowledge\Research\DataTransferObject\CollectedPages;
use Kanvas\Intelligence\Knowledge\Research\DataTransferObject\ResearchResult;
use Kanvas\Intelligence\Knowledge\Research\Enums\PublicEmailDomainEnum;
use Kanvas\Intelligence\Knowledge\Research\Services\CompanyProfileService;
use Kanvas\Intelligence\Knowledge\Research\Services\WebsitePageCollector;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeComponents;
use Kanvas\Users\Models\Users;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * The background check: collect the website, one structured call, write the profile, index the
 * rewrites company-wide. Every stage lands on the onboarding row so the screen can poll it, and a
 * failure is recorded there rather than thrown: the job must not retry a website that cannot be read.
 */
final class ResearchCompanyWebsiteAction
{
    public const string SOURCE_TYPE = 'company_website';
    public const string KNOWLEDGE_DISABLED_NOTE = 'knowledge_disabled';
    public const string PUBLIC_EMAIL_DOMAIN = 'public_email_domain';
    public const string WEBSITE_NOT_REACHABLE = 'website_not_reachable';
    public const string RESEARCH_FAILED = 'research_failed';

    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly Users $user,
        private readonly string $website,
        private readonly ?CompanyResearchAgent $handler = null,
        private readonly ?WebsitePageCollector $collector = null,
        private readonly CompanyProfileService $profiles = new CompanyProfileService(),
    ) {
    }

    public function execute(): ResearchResult
    {
        $row = CompanyOnboarding::forCompany($this->app, $this->company);

        if (PublicEmailDomainEnum::isPublic(WebsiteUrl::host(WebsiteUrl::withScheme($this->website)))) {
            $result = new ResearchResult(status: ResearchStatusEnum::SKIPPED, error: self::PUBLIC_EMAIL_DOMAIN);
            $this->record($row, [
                'status' => ResearchStatusEnum::SKIPPED->value,
                'website' => $this->website,
                'error' => self::PUBLIC_EMAIL_DOMAIN,
                'finished_at' => Carbon::now()->toIso8601String(),
            ]);
            $this->emit($row, 'onboarding.research.finished', $result);

            return $result;
        }

        $previousUrls = array_map('strval', (array) ($row?->dataGet('research.pages', []) ?? []));

        try {
            $this->record($row, [
                'status' => ResearchStatusEnum::COLLECTING->value,
                'website' => $this->website,
                'started_at' => Carbon::now()->toIso8601String(),
                'finished_at' => null,
                'error' => null,
                'note' => null,
            ]);

            $pages = ($this->collector ?? new WebsitePageCollector($this->app))->collect($this->website);
            if ($pages->isEmpty()) {
                throw new ValidationException('No pages could be read from ' . $this->website);
            }

            $this->record($row, [
                'status' => ResearchStatusEnum::EXTRACTING->value,
                'collector' => $pages->collector,
                'pages_found' => count($pages->pages),
            ]);

            $response = $this->handler()->promptWithConfig(CompanyResearchAgent::promptFor($pages));
            $structured = $response instanceof StructuredAgentResponse ? $response->structured : [];
            $profile = (array) ($structured['profile'] ?? []);
            $this->profiles->write($this->company, $profile);

            $knowledgeEnabled = KnowledgeComponents::knowledgeEnabled($this->app);
            [$indexed, $chunks] = $knowledgeEnabled
                ? $this->index($pages, (array) ($structured['pages'] ?? []), $previousUrls)
                : [0, 0];

            $result = new ResearchResult(
                status: ResearchStatusEnum::INDEXED,
                collector: $pages->collector,
                pagesFound: count($pages->pages),
                pagesIndexed: $indexed,
                knowledgeCount: $chunks,
                profile: $profile,
                note: $knowledgeEnabled ? null : self::KNOWLEDGE_DISABLED_NOTE,
            );
            $this->record($row, [
                'status' => ResearchStatusEnum::INDEXED->value,
                'pages_indexed' => $indexed,
                'knowledge_count' => $chunks,
                'pages' => $knowledgeEnabled ? $pages->urls() : $previousUrls,
                'note' => $result->note,
                'finished_at' => Carbon::now()->toIso8601String(),
            ]);
            $this->emit($row, 'onboarding.research.finished', $result);

            return $result;
        } catch (Throwable $exception) {
            if (! $exception instanceof SsrfException && ! $exception instanceof ValidationException) {
                report($exception);
            }

            // The message can name a resolved private address or a vendor's internals; the
            // screen gets a code, Sentry gets the detail.
            $error = match (true) {
                $exception instanceof SsrfException => self::WEBSITE_NOT_REACHABLE,
                $exception instanceof ValidationException => $exception->getMessage(),
                default => self::RESEARCH_FAILED,
            };
            $result = new ResearchResult(status: ResearchStatusEnum::FAILED, error: $error);
            $this->record($row, [
                'status' => ResearchStatusEnum::FAILED->value,
                'error' => $error,
                'finished_at' => Carbon::now()->toIso8601String(),
            ]);
            $this->emit($row, 'onboarding.research.failed', $result);

            return $result;
        }
    }

    /**
     * One document per page, keyed by URL so a re-run replaces in place; a page the model skipped is
     * indexed as collected rather than dropped. Pages gone since the last run are pruned.
     *
     * @param list<mixed> $rewrites
     * @param list<string> $previousUrls
     * @return array{0: int, 1: int} pages indexed, chunks written
     */
    private function index(CollectedPages $pages, array $rewrites, array $previousUrls): array
    {
        $scope = KnowledgeScope::forTenant($this->app->getId(), $this->company->getId());
        $indexer = KnowledgeComponents::indexer($this->app);

        $sectionsByUrl = [];
        foreach ($rewrites as $rewrite) {
            if (is_array($rewrite)) {
                $sectionsByUrl[rtrim((string) ($rewrite['url'] ?? ''), '/')] = trim((string) ($rewrite['sections'] ?? ''));
            }
        }

        $indexed = 0;
        $chunks = 0;
        foreach ($pages->pages as $page) {
            $text = $sectionsByUrl[$page['url']] ?? '';
            $text = $text !== '' ? $text : $page['content'];
            $chunks += $indexer->indexDocument(
                $scope,
                self::SOURCE_TYPE,
                $page['url'],
                $text,
            );
            $indexed++;
        }

        $store = KnowledgeComponents::store($this->app);
        foreach (array_diff($previousUrls, $pages->urls()) as $gone) {
            $store->deleteBySource($scope, self::SOURCE_TYPE, $gone);
        }

        return [$indexed, $chunks];
    }

    private function handler(): CompanyResearchAgent
    {
        if ($this->handler !== null) {
            return $this->handler;
        }

        $handler = new CompanyResearchAgent();
        $handler->setConfigurationWithoutAgent($this->app, $this->company);

        return $handler;
    }

    /**
     * @param array<string, mixed> $patch
     */
    private function record(?CompanyOnboarding $row, array $patch): void
    {
        $row?->dataSet('research', array_merge((array) $row->dataGet('research', []), $patch));
    }

    private function emit(?CompanyOnboarding $row, string $eventType, ResearchResult $result): void
    {
        $row?->emitLedgerEvent(
            $eventType,
            payload: [
                'website' => $this->website,
                'status' => $result->status->value,
                'collector' => $result->collector,
                'pages_found' => $result->pagesFound,
                'pages_indexed' => $result->pagesIndexed,
                'knowledge_count' => $result->knowledgeCount,
                'error' => $result->error,
            ],
            actorType: 'User',
            actorId: $this->user->getId(),
        );
    }
}
