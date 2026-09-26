<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Connectors\Salesforce\Actions\PushPeopleAction;
use Kanvas\Connectors\Salesforce\Actions\PushPropertyInterestAction;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\LeadVariantInterest;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

/**
 * Runs on ApprovalRequest::APPROVED (approval_type == 'approve_people') — the "permanent lane" per
 * src/Kanvas/Approvals/CLAUDE.md, not the entity-fired compatibility event. This is the actual push:
 * it reuses PushPeopleAction as-is, then, only if the approved request carries a
 * `lead_variant_interest_id`, also registers the property interest via PushPropertyInterestAction.
 */
#[WorkflowAction(name: 'SalesforcePushApprovedPeopleActivity')]
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

        $people = $approvalRequest->resolveEntity();

        if (! $people instanceof People) {
            return ['pushed' => false, 'reason' => 'approval request does not resolve to a People'];
        }

        return $this->executeIntegration(
            entity: $approvalRequest,
            app: $app,
            integration: IntegrationsEnum::SALESFORCE,
            additionalParams: $params,
            integrationOperation: fn ($approvalRequest, $app, $integrationCompany, $additionalParams) => $this->push(
                $people,
                $approvalRequest,
            ),
            company: $people->company,
        );
    }

    private function push(People $people, ApprovalRequest $approvalRequest): array
    {
        $pushResult = new PushPeopleAction($people)->execute();

        $interestId = $approvalRequest->payload['lead_variant_interest_id'] ?? null;
        $locationId = $interestId
            ? LeadVariantInterest::find($interestId)?->variant?->product?->get(CustomFieldEnum::SALESFORCE_LOCATION_ID->value)
            : null;

        if ($locationId) {
            new PushPropertyInterestAction($people, (string) $locationId)->execute();
        }

        return ['people' => $pushResult];
    }
}
