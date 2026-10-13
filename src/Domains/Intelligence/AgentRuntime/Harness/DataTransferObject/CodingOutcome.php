<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject;

use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\CodingOutcomeEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;

/**
 * One ending of a coding job, with what a person needs to know about it. Built by the five places a
 * job can end and consumed by exactly one writer, `RecordCodingOutcomeAction`.
 */
final readonly class CodingOutcome
{
    private function __construct(
        public CodingOutcomeEnum $kind,
        /** Null when the ending happened long after the run, and the diff was recorded at the time. */
        public ?HarnessDiff $diff = null,
        public ?string $reason = null,
        public ?string $branch = null,
        public ?string $pullRequest = null,
        public ?string $pullRequestError = null,
        public ?ApprovalRequest $approval = null,
        public ?int $decidedByUserId = null,
    ) {
    }

    public static function awaitingPush(HarnessDiff $diff, ApprovalRequest $approval, ?string $branch): self
    {
        return new self(CodingOutcomeEnum::AWAITING_PUSH, $diff, branch: $branch, approval: $approval);
    }

    public static function reviewOnly(HarnessDiff $diff): self
    {
        return new self(CodingOutcomeEnum::REVIEW_ONLY, $diff);
    }

    /**
     * @param array{pushed: bool, branch?: string, files?: int, error?: string, pull_request?: string, pull_request_error?: string} $push
     */
    public static function fromPush(
        array $push,
        ?HarnessDiff $diff = null,
        ?string $fallbackBranch = null,
        ?int $decidedByUserId = null,
    ): self {
        $branch = $push['branch'] ?? $fallbackBranch;

        if (($push['pushed'] ?? false) !== true) {
            return new self(
                CodingOutcomeEnum::PUSH_FAILED,
                $diff,
                reason: 'The work is done but the push failed: ' . (string) ($push['error'] ?? 'unknown reason')
                    . ($branch !== null ? ' It is still in the workspace on branch `' . $branch . '`.' : ''),
                branch: $branch,
                decidedByUserId: $decidedByUserId,
            );
        }

        return new self(
            CodingOutcomeEnum::PUSHED,
            $diff,
            branch: $branch,
            pullRequest: $push['pull_request'] ?? null,
            pullRequestError: $push['pull_request_error'] ?? null,
            decidedByUserId: $decidedByUserId,
        );
    }

    public static function pushRejected(?string $reason, ?int $decidedByUserId, ?string $branch): self
    {
        return new self(
            CodingOutcomeEnum::PUSH_REJECTED,
            reason: 'The push was rejected' . ($reason !== null && $reason !== '' ? ': ' . $reason : '.')
                . ($branch !== null ? ' The work remains on branch `' . $branch . '` in the workspace.' : ''),
            branch: $branch,
            decidedByUserId: $decidedByUserId,
        );
    }

    public static function pushExpired(?string $branch): self
    {
        return new self(
            CodingOutcomeEnum::PUSH_EXPIRED,
            reason: 'Nobody approved the push before the request expired.'
                . ($branch !== null ? ' The work remains on branch `' . $branch . '` in the workspace.' : ''),
            branch: $branch,
        );
    }

    public static function pushCancelled(?string $reason, ?string $branch): self
    {
        return new self(
            CodingOutcomeEnum::PUSH_CANCELLED,
            reason: 'The push approval was withdrawn' . ($reason !== null && $reason !== '' ? ': ' . $reason : '.'),
            branch: $branch,
        );
    }

    public static function failed(?HarnessDiff $diff, string $reason): self
    {
        return new self(CodingOutcomeEnum::FAILED, $diff, reason: $reason);
    }

    public static function cancelled(?HarnessDiff $diff, string $reason): self
    {
        return new self(CodingOutcomeEnum::CANCELLED, $diff, reason: $reason);
    }

    /**
     * What goes on the task. Only the keys this ending knows are set, so a late ending (a push
     * approved hours later) overlays the diff recorded when the run finished instead of erasing it.
     *
     * @return array<string, mixed>
     */
    public function taskResult(AgentTaskSession $session): array
    {
        return array_filter([
            'outcome' => $this->kind->value,
            'summary' => $this->diff?->summary(),
            'files' => $this->diff?->paths(),
            'branch' => $this->branch ?? $session->branch,
            'handoff' => $session->handoff,
            'pull_request' => $this->pullRequest,
            'decided_by_users_id' => $this->decidedByUserId,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** Where the work is, or why it is not where anyone expected. */
    public function line(): string
    {
        return match ($this->kind) {
            CodingOutcomeEnum::AWAITING_PUSH => 'Waiting on your approval to push `' . (string) $this->branch . '`.',
            CodingOutcomeEnum::PUSHED => $this->pushedLine(),
            CodingOutcomeEnum::REVIEW_ONLY => 'Nothing has been pushed — the diff is waiting on the workspace for review.',
            default => (string) $this->reason,
        };
    }

    private function pushedLine(): string
    {
        $line = 'Pushed to `' . (string) $this->branch . '`.';

        if ($this->pullRequest !== null) {
            return $line . ' Pull request open for review: ' . $this->pullRequest;
        }

        return $this->pullRequestError !== null
            ? $line . ' No pull request was opened (' . $this->pullRequestError . ').'
            : $line;
    }
}
