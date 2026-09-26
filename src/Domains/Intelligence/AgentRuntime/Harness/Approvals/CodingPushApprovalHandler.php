<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Approvals;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Approvals\Contracts\ApprovalHandlerInterface;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\PushSessionAction;
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
 */
class CodingPushApprovalHandler implements ApprovalHandlerInterface
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function handle(ApprovalRequest $request, ?UserInterface $approver): array
    {
        /** @var AgentTaskSession|null $session */
        $session = AgentTaskSession::query()->where('task_id', (int) $request->entity_id)->latest('id')->first();

        if ($session === null) {
            return ['pushed' => false, 'error' => 'The coding session for this task no longer exists.'];
        }

        return [
            ...new PushSessionAction($session)->execute(),
            'approved_by' => $approver?->getId(),
        ];
    }
}
