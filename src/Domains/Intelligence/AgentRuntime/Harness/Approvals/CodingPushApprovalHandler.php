<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Approvals;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Approvals\Contracts\ApprovalHandlerInterface;
use Kanvas\Approvals\Contracts\ApprovalRejectionHandlerInterface;
use Kanvas\Approvals\Contracts\ApprovalWithdrawalHandlerInterface;
use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\PushSessionAction;
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\RecordCodingOutcomeAction;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\CodingOutcome;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Override;

/**
 * Pushes a coding session's branch once a human has approved it.
 *
 * **Opt-in, and off by default.** A branch push needs no approval — an engineer does not ask before
 * pushing their own branch, and the review happens at the merge. A session whose tenant has no
 * `coding_push` policy is pushed as soon as it finishes; this exists for the teams who want a look
 * first, and it runs only because someone configured that.
 *
 * Deliberately the SYNC lane: the caller learns whether the push actually landed. An approval that
 * silently failed to push is worse than a refusal, because everyone believes the work is on its way.
 *
 * Every answer — yes, no, nobody answered — ends in `RecordCodingOutcomeAction`, because the task
 * parked on this request and whoever delegated the job are waiting for exactly that.
 */
class CodingPushApprovalHandler implements ApprovalHandlerInterface, ApprovalRejectionHandlerInterface, ApprovalWithdrawalHandlerInterface
{
    private const array NO_SESSION = ['pushed' => false, 'error' => 'The coding session for this task no longer exists.'];

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function handle(ApprovalRequest $request, ?UserInterface $approver): array
    {
        $session = $this->sessionFor($request);

        if ($session === null) {
            return self::NO_SESSION;
        }

        $push = new PushSessionAction($session)->execute();

        new RecordCodingOutcomeAction(
            $session,
            CodingOutcome::fromPush($push, fallbackBranch: $session->branch, decidedByUserId: $approver?->getId()),
            announce: true,
        )->execute();

        return [
            ...$push,
            'approved_by' => $approver?->getId(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function reject(ApprovalRequest $request, ?UserInterface $approver, ?string $reason): array
    {
        $session = $this->sessionFor($request);

        if ($session === null) {
            return self::NO_SESSION;
        }

        new RecordCodingOutcomeAction(
            $session,
            CodingOutcome::pushRejected($reason, $approver?->getId(), $session->branch),
            announce: true,
        )->execute();

        return ['pushed' => false, 'rejected_by' => $approver?->getId()];
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function withdrawn(ApprovalRequest $request, ?string $reason): array
    {
        $session = $this->sessionFor($request);

        if ($session === null) {
            return self::NO_SESSION;
        }

        $outcome = $request->status === ApprovalStatusEnum::EXPIRED
            ? CodingOutcome::pushExpired($session->branch)
            : CodingOutcome::pushCancelled($reason, $session->branch);

        new RecordCodingOutcomeAction($session, $outcome, announce: true)->execute();

        return ['pushed' => false, 'withdrawn' => $request->status->value];
    }

    private function sessionFor(ApprovalRequest $request): ?AgentTaskSession
    {
        /** @var AgentTaskSession|null $session */
        $session = AgentTaskSession::query()->where('task_id', (int) $request->entity_id)->latest('id')->first();

        return $session;
    }
}
