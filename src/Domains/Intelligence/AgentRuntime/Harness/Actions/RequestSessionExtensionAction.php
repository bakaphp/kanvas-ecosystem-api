<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Actions;

use Kanvas\Approvals\Actions\RequestApprovalAction;
use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Approvals\Repositories\ApprovalPolicyRepository;
use Kanvas\Intelligence\AgentRuntime\Harness\Concerns\PostsSessionActivity;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\HarnessFactory;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\NervousSystem\Plan\Models\Task;

/**
 * Pauses a session at its time or cost limit and asks a human whether it gets another block.
 *
 * Returns null when the tenant has no `coding_extension` policy, and the caller stops the run instead.
 * Opt-in like the push gate: a limit is a decision somebody made, and silently turning it into
 * a question would mean nobody's limit is real.
 */
class RequestSessionExtensionAction
{
    use PostsSessionActivity;

    public const string APPROVAL_TYPE = 'coding_extension';

    /**
     * @param array{text: string, files: list<string>, silent_minutes: int|null} $postMortem
     */
    public function __construct(
        private readonly AgentTaskSession $session,
        private readonly string $limit,
        private readonly array $postMortem,
    ) {
    }

    public function execute(): ?ApprovalRequest
    {
        /** @var Task|null $task */
        $task = $this->session->task;

        if ($task === null) {
            return null;
        }

        $policy = ApprovalPolicyRepository::findByType($task, self::APPROVAL_TYPE);

        if ($policy === null) {
            return null;
        }

        // Parked BEFORE the interrupt: the abort closes the turn, and a poll that saw that first would
        // finalize the run as failed while the question was still being asked.
        $this->session->status = HarnessStatusEnum::AWAITING_EXTENSION->value;
        $this->session->error_message = $this->postMortem['text'];
        $this->session->touchHeartbeat();
        $this->session->saveOrFail();

        HarnessFactory::interrupt($this->session);

        $request = new RequestApprovalAction(
            entity: $task,
            policy: $policy,
            origin: ApprovalOriginEnum::SYSTEM,
            requestedBy: $this->session->user,
            payload: [
                'session_uuid' => $this->session->uuid,
                'limit' => $this->limit,
                'extensions_so_far' => $this->session->limit_extensions,
                'post_mortem' => $this->postMortem['text'],
                'silent_minutes' => $this->postMortem['silent_minutes'],
                'files' => $this->postMortem['files'],
                'branch' => $this->session->branch,
                'model' => $this->session->model,
                'estimated_cost_usd' => $this->session->estimated_cost,
            ],
        )->execute();

        $this->postBlockingAsk(
            $this->session,
            '⏸ ' . $this->postMortem['text']
                . "\n\nIt is paused, not stopped. Approve the `coding_extension` request to give it another "
                . 'block of time and budget; it stops for good if nobody does.',
            'coding_limit_reached',
            'Coding job paused at its limit',
        );

        return $request;
    }
}
