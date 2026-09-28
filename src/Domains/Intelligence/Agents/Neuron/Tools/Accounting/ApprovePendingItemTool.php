<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Illuminate\Support\Carbon;
use Kanvas\Approvals\Actions\ApproveAction;
use Kanvas\Approvals\Enums\ApprovalOutcomeEnum;
use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\VerifiesApprovalAuthority;
use Kanvas\Scribe\Approvals\Actions\ResolveApprovalAction;
use Kanvas\Scribe\Approvals\Actions\ResolveApproverEmailAction;
use Kanvas\Scribe\Approvals\Enums\ApprovalQueueStatusEnum;
use Kanvas\Scribe\Approvals\Models\ApprovalQueueItem;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * Approves any pending item — a bill, an invoice, an expense, or any future type — and carries out
 * whatever that approval means (e.g. pushing to Acumatica).
 *
 * Prefers the generic Kanvas\Approvals domain and falls back to the legacy accounting.approval_queue
 * when the tenant has no policy configured. That is the cutover: seeding a policy moves a tenant onto
 * the new engine one at a time, and a tenant with no policy behaves exactly as it did before.
 *
 * The result shape is identical on both paths, so the agent guidance in AccountsPayableAgent /
 * AccountsReceivableAgent reads `pushed`, `push_error` and the source fields unchanged.
 */
#[AgentTool(name: 'Approve Pending Item', category: 'accounting')]
class ApprovePendingItemTool extends Tool
{
    use HasKanvasContext;
    use VerifiesApprovalAuthority;

    public function __construct()
    {
        parent::__construct(
            name: 'approve_pending_item',
            description: 'Approves a pending item in the approval queue (e.g. a bill left pending by '
                . 'create_ap_bill, or an invoice left pending by create_ar_invoice) and carries out its action — '
                . 'for a bill/invoice, that means approving it in Kanvas and pushing it to Acumatica. Works for '
                . 'any approval type in the queue, not just invoices. Only the approver configured on that '
                . 'specific record\'s vendor/customer may call this — call it only when that specific person '
                . 'explicitly asks to approve something, never on your own initiative or on behalf of anyone else.',
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
                description: 'The kind of record pending approval, e.g. "bill" or "invoice".',
                required: true,
            ),
            new ToolProperty(
                name: 'target_id',
                type: PropertyType::INTEGER,
                description: 'The Kanvas id of that record — the bill_id from create_ap_bill, or the invoice_id '
                    . 'from create_ar_invoice.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $target_type, int $target_id): array
    {
        // Normalised once at the boundary, like check_approval_status: the generic lookup composes an
        // approval_type and the legacy one matches target_type raw, so an LLM sending " bill " would
        // otherwise resolve on one path and miss on the other.
        $type = trim($target_type);

        $request = $this->genericRequest($type, $target_id);

        return $request !== null
            ? $this->approveGeneric($request, $type)
            : $this->approveLegacy($type, $target_id);
    }

    /**
     * Matched on approval_type rather than a target_type -> model-class map, so adding a new
     * approvable type stays a policy row and never touches this tool.
     */
    private function genericRequest(string $target_type, int $target_id): ?ApprovalRequest
    {
        /** @var ApprovalRequest|null $request */
        $request = ApprovalRequest::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('approval_type', ApprovalRequest::approvalTypeFor($target_type))
            ->where('entity_id', $target_id)
            ->where('status', ApprovalStatusEnum::PENDING->value)
            ->where('is_deleted', false)
            ->latest('id')
            ->first();

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function approveGeneric(ApprovalRequest $request, string $target_type): array
    {
        $approverEmails = $request->pendingApproverEmails();

        if ($approverEmails === []) {
            return [
                'approved' => false,
                'reason' => 'no_approver_configured',
                'message' => "No approver is configured on this {$target_type}'s vendor/customer.",
            ];
        }

        // Still the email check, because the identity that matters here is the sender's Slack profile
        // email resolved by SlackUserResolverService, not their Kanvas login.
        if (! $this->isAuthorizedApprover($approverEmails)) {
            return [
                'approved' => false,
                'reason' => 'not_authorized',
                'message' => 'Only an approver configured for this vendor/customer can approve this.',
            ];
        }

        try {
            $result = new ApproveAction($request, $this->user)->execute();
        } catch (ValidationException|Throwable $e) {
            return [
                'approved' => false,
                'reason' => 'resolve_failed',
                'message' => $e->getMessage(),
            ];
        }

        return match ($result->outcome) {
            ApprovalOutcomeEnum::APPROVED => $this->approvedPayload($result->handlerResult ?? []),
            ApprovalOutcomeEnum::STILL_PENDING => [
                'approved' => false,
                'recorded' => true,
                'reason' => 'awaiting_quorum',
                'message' => "Your approval is recorded. This step needs {$result->needed} approvals and "
                    . "has {$result->have}. Do not update the tracking sheet yet.",
            ],
            ApprovalOutcomeEnum::ADVANCED => [
                'approved' => false,
                'recorded' => true,
                'reason' => 'awaiting_next_step',
                'message' => 'Your approval is recorded and this step is cleared. It now needs step '
                    . "{$result->step} to sign off. Do not update the tracking sheet yet.",
            ],
            default => [
                'approved' => false,
                'reason' => 'already_resolved',
                'message' => 'Someone else already resolved this request.',
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function approveLegacy(string $target_type, int $target_id): array
    {
        $item = ApprovalQueueItem::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('target_type', $target_type)
            ->where('target_id', $target_id)
            ->where('status', ApprovalQueueStatusEnum::PENDING->value)
            ->latest('id')
            ->first();

        if ($item === null) {
            return [
                'approved' => false,
                'reason' => 'not_found',
                'message' => "No pending approval found for {$target_type} {$target_id}.",
            ];
        }

        $approverEmails = new ResolveApproverEmailAction($target_type, $target_id)->execute();

        if ($approverEmails === []) {
            return [
                'approved' => false,
                'reason' => 'no_approver_configured',
                'message' => "No approver email is configured on this {$target_type}'s vendor/customer.",
            ];
        }

        if (! $this->isAuthorizedApprover($approverEmails)) {
            return [
                'approved' => false,
                'reason' => 'not_authorized',
                'message' => 'Only the approver configured for this vendor/customer can approve this.',
            ];
        }

        try {
            $result = new ResolveApprovalAction($item, $this->user)->execute();
        } catch (ValidationException|Throwable $e) {
            return [
                'approved' => false,
                'reason' => 'resolve_failed',
                'message' => $e->getMessage(),
            ];
        }

        return $this->approvedPayload($result);
    }

    /**
     * The single shape both paths return, so the agents' guidance never has to know which engine ran.
     *
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function approvedPayload(array $result): array
    {
        $approvedBy = (string) $this->user->email;
        $approvedAt = Carbon::now()->toDateString();
        $evidence = "Approved by {$approvedBy} on {$approvedAt}";

        return array_merge($result, [
            'approved' => true,
            'approved_by' => $approvedBy,
            'approved_at' => $approvedAt,
            'next' => ($result['pushed'] ?? false)
                ? "Pushed to Acumatica. Now: (1) add a note with the approval evidence (\"{$evidence}\"). "
                    . '(2) If source_attachment_url is present, attach it (attach_bill_file/attach_invoice_file) '
                    . 'now that this record is actually pushed. '
                    . '(3) If source_email_message_id is present, reply_to_email with that same '
                    . 'evidence on the original invoice email. '
                    . '(4) In the sheet, find the row for this record and update column D (Status) to '
                    . '"Approved", column E (Approved Date) to approved_at, and column F (Approved By) to '
                    . 'approved_by.'
                : 'Approved in Kanvas but the push to Acumatica failed: '
                    . ($result['push_error'] ?? $result['handler_error'] ?? 'unknown error')
                    . '. It needs manual attention. Do NOT mark the sheet Approved.',
        ]);
    }
}
