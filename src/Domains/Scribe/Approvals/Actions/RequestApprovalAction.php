<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Approvals\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Scribe\Approvals\Enums\ApprovalQueueStatusEnum;
use Kanvas\Scribe\Approvals\Models\ApprovalQueueItem;

/**
 * Drops a durable, pending row in the approval queue for any record type. ResolveApprovalAction
 * later dispatches on action_type to carry out whatever that approval means — this action only
 * ever records the request.
 */
class RequestApprovalAction
{
    public function __construct(
        protected readonly AppInterface $app,
        protected readonly CompanyInterface $company,
        protected readonly string $actionType,
        protected readonly string $targetType,
        protected readonly int $targetId,
        protected readonly ?UserInterface $requestedByUser = null,
        protected readonly array $payload = [],
    ) {
    }

    public function execute(): ApprovalQueueItem
    {
        return ApprovalQueueItem::create([
            'apps_id' => $this->app->getId(),
            'companies_id' => $this->company->getId(),
            'requested_by_users_id' => $this->requestedByUser?->getId(),
            'action_type' => $this->actionType,
            'target_type' => $this->targetType,
            'target_id' => $this->targetId,
            'payload' => $this->payload,
            'status' => ApprovalQueueStatusEnum::PENDING,
        ]);
    }
}
