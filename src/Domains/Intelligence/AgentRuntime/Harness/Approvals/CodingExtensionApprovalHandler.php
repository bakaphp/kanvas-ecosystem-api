<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Approvals;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Approvals\Contracts\ApprovalHandlerInterface;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\HarnessFactory;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Override;

/**
 * Resumes a session paused at its limit with one more block of time and budget.
 *
 * Nothing re-dispatches the poller: it kept ticking while the session was parked, so flipping the
 * status back is all it needs to carry on. Rejection needs no handler either — the parked poller sees
 * the request close and stops the run itself.
 */
class CodingExtensionApprovalHandler implements ApprovalHandlerInterface
{
    private const string CONTINUE_PROMPT = 'You were paused at your time/cost limit and a person approved '
        . 'more. Continue the task from exactly where you stopped — do not redo work that is already in '
        . 'the workspace.';

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function handle(ApprovalRequest $request, ?UserInterface $approver): array
    {
        /** @var AgentTaskSession|null $session */
        $session = AgentTaskSession::query()
            ->where('task_id', (int) $request->entity_id)
            ->fromApp($request->apps_id)
            ->latest('id')
            ->first();

        if ($session === null || $session->harnessStatus() !== HarnessStatusEnum::AWAITING_EXTENSION) {
            return ['resumed' => false, 'error' => 'The coding session is no longer waiting on more time.'];
        }

        $harness = HarnessFactory::forSession($session);

        // Counted before the new prompt: every turn closed so far, including the one the pause
        // aborted, belongs to work already accounted for.
        $closedTurns = $harness->poll($session)->closedTurns;
        $harness->ask($session, self::CONTINUE_PROMPT);

        $session->turn_offset = $closedTurns;
        $session->limit_extensions++;
        $session->status = HarnessStatusEnum::RUNNING->value;
        $session->error_message = null;
        $session->touchHeartbeat();
        $session->saveOrFail();

        return [
            'resumed' => true,
            'extensions' => $session->limit_extensions,
            'approved_by' => $approver?->getId(),
        ];
    }
}
