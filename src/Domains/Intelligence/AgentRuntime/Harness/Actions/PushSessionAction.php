<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Actions;

use Baka\Support\Str;
use Kanvas\Connectors\OpenCode\Actions\PushSessionBranchAction;
use Kanvas\Connectors\OpenCode\DataTransferObject\CodingRepository;
use Kanvas\Connectors\OpenCode\Enums\AgentCustomFieldEnum;
use Kanvas\Connectors\OpenCode\Services\GitHubRepositoryService;
use Kanvas\Connectors\OpenCode\Services\RepoAllowListService;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Throwable;

/**
 * Pushes a finished session's branch.
 *
 * **A branch is not a gate.** An engineer pushes their own branch without asking anyone; the review
 * happens at the merge, where it belongs and where the tools for it already exist. Holding the branch
 * back bought no safety — the work was already done, it just sat somewhere only Kanvas could see, and
 * nobody could look at it with the tools they normally use.
 *
 * What still cannot happen without a deliberate decision is the merge, and a push to a trunk:
 * `PushSessionBranchAction` refuses one unless the agent carries `CODING_ALLOW_TRUNK_PUSH`.
 *
 * Both the automatic path and the approval path come through here, so they cannot drift.
 *
 * @see PushSessionBranchAction for the guards that apply to every push
 */
class PushSessionAction
{
    public function __construct(
        private readonly AgentTaskSession $session,
    ) {
    }

    /**
     * @return array{pushed: bool, branch?: string, files?: int, error?: string, pull_request?: string, pull_request_error?: string}
     */
    public function execute(): array
    {
        if ($this->session->branch === null) {
            // Attach mode shares one workspace and creates no branch, so there is nothing to push.
            return ['pushed' => false, 'error' => 'This session has no branch of its own.'];
        }

        try {
            $repository = $this->repository();

            $result = new PushSessionBranchAction(
                session: $this->session,
                commitMessage: $this->commitMessage(),
                protectedPaths: $repository?->protectedPaths ?? [],
                remoteUrl: $repository?->cloneUrl,
                baseBranch: $repository?->baseBranch ?? 'main',
            )->execute();
        } catch (Throwable $e) {
            // Reported rather than thrown: the session is already finished and its outcome recorded. A
            // failed push is news to deliver, not a reason to unwind what happened.
            report($e);

            return ['pushed' => false, 'error' => $e->getMessage()];
        }

        return [
            'pushed' => $result['pushed'],
            'branch' => $result['branch'],
            'files' => $result['files'],
            ...($result['pushed'] ? $this->openPullRequest($repository, $result['branch']) : []),
        ];
    }

    /**
     * Opens the pull request, because a branch nobody can see is not a finished handover.
     *
     * Soft on every failure: the push already landed, so the work is safe. A missing
     * `Pull requests: write` permission is worth saying out loud, never worth reporting the push as
     * failed.
     *
     * @return array{pull_request?: string, pull_request_error?: string}
     */
    private function openPullRequest(?CodingRepository $repository, string $branch): array
    {
        $token = Str::trimToNull((string) $this->session->agent?->get(AgentCustomFieldEnum::GIT_TOKEN->value));

        if ($repository === null || $token === null) {
            return [];
        }

        $result = new GitHubRepositoryService($token, $repository->cloneUrl)->openFor(
            $branch,
            $repository->baseBranch,
            (string) ($this->session->task?->title ?? 'Agent change'),
            $this->pullRequestBody()
        );

        if ($result === null) {
            return [];
        }

        if (! isset($result['url'])) {
            return ['pull_request_error' => (string) ($result['error'] ?? 'unknown reason')];
        }

        // Stored, because a follow-up needs to find the review to answer — and needs to know a pull
        // request already exists so it updates that one instead of opening a second for the same branch.
        $this->session->pull_request_url = (string) $result['url'];
        $this->session->saveOrFail();

        return ['pull_request' => (string) $result['url']];
    }

    /**
     * The agent's own handoff is already a decent description — what it did, what it decided and why,
     * and what it was unsure about. Better than anything generated a second time from the diff.
     */
    private function pullRequestBody(): string
    {
        $lines = ['Opened by Kanvas for coding job #' . $this->session->task_id . '.'];
        $handoff = Str::trimToNull((string) $this->session->handoff);

        if ($handoff !== null) {
            $lines[] = '';
            $lines[] = '## What the agent reported';
            $lines[] = '';
            $lines[] = '```json';
            $lines[] = $handoff;
            $lines[] = '```';
        }

        $lines[] = '';
        $lines[] = '_Written by an agent. Review before merging._';

        return implode("\n", $lines);
    }

    private function commitMessage(): string
    {
        $title = (string) ($this->session->task?->title ?? 'Agent change');

        return trim($title) . "\n\nDispatched by Kanvas as coding job #" . $this->session->task_id . '.';
    }

    private function repository(): ?CodingRepository
    {
        $agent = $this->session->agent;
        $slug = $this->session->repo_slug;

        if ($agent === null || $slug === null) {
            return null;
        }

        return new RepoAllowListService($agent)->resolveOrFail($slug);
    }
}
