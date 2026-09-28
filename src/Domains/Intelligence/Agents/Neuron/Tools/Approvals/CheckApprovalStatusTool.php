<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Approvals;

use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Approvals\Models\ApprovalRequestApprover;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Approvals\Enums\ApprovalQueueStatusEnum;
use Kanvas\Scribe\Approvals\Models\ApprovalQueueItem;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Answers "is this approved, and if not who are we waiting on" for any record — read-only.
 *
 * Split from approve_pending_item deliberately: an agent should be able to report where something
 * stands without the tool that decides it being in reach. It reads the generic approvals domain and
 * falls back to the legacy accounting queue, so it gives the same answer either side of a tenant's
 * cutover.
 */
#[AgentTool(name: 'Check Approval Status', category: 'approvals')]
class CheckApprovalStatusTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'check_approval_status',
            description: 'Check whether a record is approved, still waiting, rejected, expired or was never '
                . 'gated at all — and when it is waiting, who it is waiting on and at which step. Use this to '
                . 'answer "is bill 1072 approved?", "who still has to sign off on invoice 88?" or before telling '
                . 'someone an item is done. Read-only: it never approves anything.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'target_type',
                type: PropertyType::STRING,
                description: 'The kind of record, e.g. "bill", "invoice" or "expense".',
                required: true,
            ),
            new ToolProperty(
                name: 'target_id',
                type: PropertyType::INTEGER,
                description: 'The Kanvas id of that record.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $target_type, int $target_id): array
    {
        $type = trim($target_type);

        $request = $this->latestRequest($type, $target_id);

        if ($request !== null) {
            return $this->describe($request, $type, $target_id);
        }

        return $this->describeLegacy($type, $target_id);
    }

    private function latestRequest(string $targetType, int $targetId): ?ApprovalRequest
    {
        /** @var ApprovalRequest|null $request */
        $request = ApprovalRequest::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('approval_type', ApprovalRequest::approvalTypeFor($targetType))
            ->where('entity_id', $targetId)
            ->where('is_deleted', false)
            ->latest('id')
            ->first();

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(ApprovalRequest $request, string $targetType, int $targetId): array
    {
        $pending = $request->status === ApprovalStatusEnum::PENDING;

        return [
            'found' => true,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'approved' => $request->status === ApprovalStatusEnum::APPROVED,
            'status' => $request->status->value,
            'current_step' => $pending ? $request->current_step : null,
            'awaiting' => $pending ? $request->pendingApproverEmails() : [],
            'approvals_at_current_step' => $pending ? $request->approvalsAtCurrentStep() : null,
            'approvals_needed_at_current_step' => $pending ? $request->requiredApprovalsAtCurrentStep() : null,
            'unassigned' => $request->isUnassigned(),
            'resolved_by' => $request->resolvedByUser?->email,
            'resolved_at' => $request->resolved_at?->toDateString(),
            'reason' => $request->reason,
            // "Approved" and "landed in the ERP" are different facts; the agent must not report the
            // second when it only knows the first.
            'downstream_result' => $request->metadata['handler_result'] ?? null,
            'decisions' => $this->decisions($request),
            'message' => $this->message($request, $targetType, $targetId),
        ];
    }

    /**
     * The full trail, not just the outcome — "two people were asked, one declined" is usually the
     * answer somebody actually wants.
     *
     * @return array<int, array<string, mixed>>
     */
    private function decisions(ApprovalRequest $request): array
    {
        return $request->approvers()
            ->orderBy('step')
            ->orderBy('id')
            ->get()
            ->map(fn (ApprovalRequestApprover $row): array => [
                'email' => $row->email,
                'step' => $row->step,
                'decision' => $row->decision->value,
                'decided_at' => $row->decided_at?->toDateString(),
                'comment' => $row->comment,
            ])
            ->all();
    }

    private function message(ApprovalRequest $request, string $targetType, int $targetId): string
    {
        if ($request->isUnassigned() && $request->status === ApprovalStatusEnum::PENDING) {
            return "This {$targetType} is waiting for approval but nobody is configured to approve it. "
                . 'Someone has to add an approver before it can move.';
        }

        return match ($request->status) {
            ApprovalStatusEnum::APPROVED => "This {$targetType} is approved.",
            ApprovalStatusEnum::REJECTED => "This {$targetType} was rejected."
                . ($request->reason !== null ? " Reason: {$request->reason}" : ''),
            ApprovalStatusEnum::EXPIRED => "Nobody decided on this {$targetType} before it expired.",
            ApprovalStatusEnum::CANCELLED => "The approval for this {$targetType} was withdrawn.",
            ApprovalStatusEnum::PENDING => sprintf(
                'Waiting on %s at step %d (%d of %d approvals).',
                implode(', ', $request->pendingApproverEmails()) ?: 'nobody',
                $request->current_step,
                $request->approvalsAtCurrentStep(),
                $request->requiredApprovalsAtCurrentStep(),
            ),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function describeLegacy(string $targetType, int $targetId): array
    {
        /** @var ApprovalQueueItem|null $item */
        $item = ApprovalQueueItem::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->latest('id')
            ->first();

        if ($item === null) {
            return [
                'found' => false,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'approved' => null,
                'message' => "No approval has ever been requested for {$targetType} {$targetId}. Either it does "
                    . 'not need approval, or it was never submitted. Do not retry this lookup.',
            ];
        }

        return [
            'found' => true,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'approved' => $item->status === ApprovalQueueStatusEnum::APPROVED,
            'status' => $item->status->value,
            'resolved_by' => $item->approvedByUser?->email,
            'resolved_at' => $item->approved_at?->toDateString(),
            'reason' => $item->reason,
            'decisions' => [],
            'message' => $item->status === ApprovalQueueStatusEnum::PENDING
                ? "This {$targetType} is waiting for its vendor/customer approver to sign off."
                : "This {$targetType} is {$item->status->value}.",
        ];
    }
}
