<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Activities;

use Kanvas\Connectors\Intras\Activities\Concerns\PushesApprovedChangeSet;
use Kanvas\Connectors\Intras\Enums\PeopleIntrasSyncApprovalTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction(
    name: 'IntrasPushApprovedPeopleActivity',
    description: 'Writes the change set of an approved approve_people_intras_update request to SIPGO.',
)]
class PushApprovedPeopleActivity extends KanvasActivity implements WorkflowActivityInterface
{
    use PushesApprovedChangeSet;

    public $tries = 3;

    protected function approvalType(): string
    {
        return PeopleIntrasSyncApprovalTypeEnum::UPDATE->value;
    }

    protected function entityClass(): string
    {
        return People::class;
    }
}
