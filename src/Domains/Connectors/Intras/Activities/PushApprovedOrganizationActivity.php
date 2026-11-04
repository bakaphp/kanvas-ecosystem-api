<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Activities;

use Kanvas\Connectors\Intras\Activities\Concerns\PushesApprovedChangeSet;
use Kanvas\Connectors\Intras\Enums\OrganizationIntrasSyncApprovalTypeEnum;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction(
    name: 'IntrasPushApprovedOrganizationActivity',
    description: 'Writes the change set of an approved approve_organization_intras_update request to SIPGO.',
)]
class PushApprovedOrganizationActivity extends KanvasActivity implements WorkflowActivityInterface
{
    use PushesApprovedChangeSet;

    public $tries = 3;

    protected function approvalType(): string
    {
        return OrganizationIntrasSyncApprovalTypeEnum::UPDATE->value;
    }

    protected function entityClass(): string
    {
        return Organization::class;
    }
}
