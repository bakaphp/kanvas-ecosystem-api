<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;

/**
 * Entity-agnostic counterpart to a hand-written Activity like Salesforce's
 * RequestPeopleApprovalActivity: attach this to a Rule on any HasApprovals entity to open one
 * approval_type with no new code, driven entirely by Rule.params. Reach for a dedicated Activity
 * instead when opening the request needs real business logic first — choosing between two types,
 * resolving a related record, opening more than one request at once. This one only ever calls
 * requestApproval() once.
 */
#[WorkflowAction(
    name: 'Request Entity Approval',
    description: 'Opens an approval_type on the record that triggered the rule. Works on any entity that '
        . 'uses HasApprovals — no per-entity code needed. A tenant with no approval_policies row for the '
        . 'type simply stays ungated, same as calling requestApproval() directly. Use a dedicated Activity '
        . 'instead when opening the request needs real business logic first, such as choosing between two '
        . 'approval types or opening more than one request at once.',
    requiredParams: ['approval_type'],
    params: [
        'approval_type' => 'The approval_type to open. Must match an approval_policies row for this '
            . 'company and entity, or nothing opens.',
        'auto_reject_stale_pending' => 'Optional, default false. When true, an existing pending request '
            . 'of the same type is auto-rejected as superseded instead of blocking the new one.',
        'payload' => 'Optional object merged into the request payload as-is.',
    ],
)]
class RequestEntityApprovalActivity extends KanvasActivity implements WorkflowActivityInterface
{
    public $tries = 3;

    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        if (! method_exists($entity, 'requestApproval')) {
            return ['requested' => false, 'reason' => 'entity does not use HasApprovals'];
        }

        $approvalType = trim((string) ($params['approval_type'] ?? ''));

        if ($approvalType === '') {
            return ['requested' => false, 'reason' => 'missing approval_type param'];
        }

        $pending = $entity->pendingApproval($approvalType);

        if ($pending !== null) {
            if (! ($params['auto_reject_stale_pending'] ?? false)) {
                return ['requested' => false, 'reason' => 'already pending'];
            }

            $entity->supersedePendingApproval($approvalType);
        }

        /** @var ApprovalRequest|null $request */
        $request = $entity->requestApproval(
            $approvalType,
            payload: (array) ($params['payload'] ?? []),
            origin: ApprovalOriginEnum::SYSTEM,
        );

        return ['requested' => $request !== null, 'approval_request_id' => $request?->getId()];
    }
}
