<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Connectors\Intras\Actions\PushPeopleToIntrasAction;
use Kanvas\Connectors\Intras\Enums\PeopleIntrasSyncApprovalTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

/**
 * Runs on ApprovalRequest::APPROVED — the permanent lane per src/Kanvas/Approvals/CLAUDE.md. Checks
 * the approval_type itself as well as relying on the Rule condition, because a Rule without the
 * condition would otherwise run on every People approval of the company.
 */
#[WorkflowAction(
    name: 'IntrasPushApprovedPeopleActivity',
    description: 'Writes the change set of an approved approve_people_intras_update request to SIPGO.',
)]
class PushApprovedPeopleActivity extends KanvasActivity implements WorkflowActivityInterface
{
    public $tries = 3;

    /**
     * @param ApprovalRequest $approvalRequest
     */
    #[Override]
    public function execute(Model $approvalRequest, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        if ($approvalRequest->approval_type !== PeopleIntrasSyncApprovalTypeEnum::UPDATE->value) {
            return ['pushed' => false, 'reason' => 'not a SIPGO people sync approval'];
        }

        $people = $approvalRequest->resolveEntity();

        if (! $people instanceof People) {
            return ['pushed' => false, 'reason' => 'approval request does not resolve to a People'];
        }

        return $this->executeIntegration(
            entity: $approvalRequest,
            app: $app,
            integration: IntegrationsEnum::INTRAS,
            additionalParams: $params,
            integrationOperation: fn ($approvalRequest, $app, $integrationCompany, $additionalParams) => new PushPeopleToIntrasAction(
                $people,
                (array) ($approvalRequest->payload['changes'] ?? []),
            )->execute(),
            company: $people->company,
        );
    }
}
