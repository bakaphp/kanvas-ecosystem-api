<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Jobs;

use Baka\Contracts\CompanyInterface;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\OpenCode\Actions\ProvisionCodingSessionAction;
use Kanvas\Connectors\OpenCode\Actions\StopCodingSessionRuntimeAction;
use Kanvas\Connectors\OpenCode\DataTransferObject\CodingRepository;
use Kanvas\Connectors\OpenCode\DataTransferObject\SessionAttachment;
use Kanvas\Connectors\OpenCode\Services\CodingPolicy;
use Kanvas\Connectors\OpenCode\Services\RepoAllowListService;
use Kanvas\Connectors\OpenCode\Services\SessionAttachmentService;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessPrompt;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\HarnessFactory;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\Agents\Models\Agent;
use Throwable;

/**
 * Provisions a session and starts its first turn, off the request path.
 *
 * Only the launch path goes through here. Cloning a repository of any size takes minutes, and doing
 * that inside a tool call means the agent's turn sits waiting on a `git clone` — attach mode, which
 * creates nothing, stays synchronous because there is nothing to wait for.
 */
class LaunchTaskSessionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    /**
     * @param list<string> $referenceSlugs Slugs, not DTOs: a Spatie Data object does not survive the queue.
     * @param list<int> $attachmentIds Ids, not bytes: a design file does not belong in a Redis payload.
     */
    public function __construct(
        public readonly Apps $app,
        public readonly int $sessionId,
        public readonly string $brief,
        public readonly ?string $repoSlug = null,
        public readonly ?string $persona = null,
        public readonly array $referenceSlugs = [],
        public readonly array $attachmentIds = [],
    ) {
        $this->onQueue('agent-runtime');
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        /** @var AgentTaskSession|null $session */
        $session = AgentTaskSession::query()->where('id', $this->sessionId)->fromApp($this->app)->first();

        if ($session === null || ! $session->isLive()) {
            return;
        }

        $agent = $session->agent;
        $company = $session->company;

        if ($agent === null || $company === null) {
            $this->fail($session, 'The session lost its agent or company before it could start.');

            return;
        }

        try {
            // Inside the try: resolving asks GitHub whether the token can open the repository, and a
            // refusal there has to land on the session row like any other launch failure.
            $repository = $this->resolveRepository();
            $attachments = $this->loadAttachments($company);

            new ProvisionCodingSessionAction(
                session: $session,
                app: $this->app,
                company: $company,
                repository: $repository,
                references: $this->resolveReferences($agent, $repository),
                attachments: $attachments,
            )->execute();

            // No memories here: the launch path writes them into the workspace as `.kanvas/context.md`,
            // where they survive compaction instead of being spent once in the opening prompt.
            HarnessFactory::forSession($session)->start($session, new HarnessPrompt(
                task: 'TASK:' . "\n" . $this->brief,
                policy: CodingPolicy::BLOCK,
                repoRules: $repository?->rules,
                persona: $this->persona,
                attachments: SessionAttachmentService::promptBlock($attachments),
            ));
        } catch (Throwable $e) {
            $this->fail($session, $e->getMessage());

            return;
        }

        PollHarnessSessionJob::dispatch($this->app, $session->getId());
    }

    /**
     * Standing plus dispatch-named, minus the repo being worked on — dropped rather than refused, since
     * two copies of one tree with only one writable helps nobody. A named reference that cannot be
     * opened throws: the model would otherwise find no `.reference/<slug>/` and answer from imagination.
     *
     * @return list<CodingRepository>
     */
    private function resolveReferences(Agent $agent, ?CodingRepository $working): array
    {
        $allowList = new RepoAllowListService($agent);
        $references = $allowList->references();

        // `??` because a job queued before this property existed comes back with it UNINITIALIZED,
        // not defaulted, and a direct read fatals. Every job in Redis at deploy time is one of those.
        foreach ($this->referenceSlugs ?? [] as $slug) {
            $references[] = $allowList->resolveOrFail($slug);
        }

        $resolved = [];

        foreach ($references as $reference) {
            if ($reference->slug !== $working?->slug) {
                $resolved[$reference->slug] ??= $reference;
            }
        }

        return array_values($resolved);
    }

    /**
     * `??` for the same reason as the references: a job queued before this property existed comes back
     * with it uninitialized.
     *
     * @return list<SessionAttachment>
     */
    private function loadAttachments(CompanyInterface $company): array
    {
        $service = new SessionAttachmentService($this->app, $company);

        return $service->load($service->resolve($this->attachmentIds ?? []));
    }

    private function resolveRepository(): ?CodingRepository
    {
        if ($this->repoSlug === null) {
            return null;
        }

        /** @var AgentTaskSession|null $session */
        $session = AgentTaskSession::query()->where('id', $this->sessionId)->first();
        $agent = $session?->agent;

        // Same resolution as the dispatch that created this job — anything else and a repository that
        // resolved a moment ago silently becomes "no repository" here, which reads as a bare workspace.
        return $agent === null ? null : new RepoAllowListService($agent)->resolveOrFail($this->repoSlug);
    }

    /**
     * The failure has to land on the row rather than in an exception, because nothing is waiting on
     * this job's return value — the tool call that started it finished long ago.
     */
    private function fail(AgentTaskSession $session, string $reason): void
    {
        $session->status = HarnessStatusEnum::FAILED->value;
        $session->error_message = $reason;
        $session->saveOrFail();

        new StopCodingSessionRuntimeAction($session)->execute();
    }
}
