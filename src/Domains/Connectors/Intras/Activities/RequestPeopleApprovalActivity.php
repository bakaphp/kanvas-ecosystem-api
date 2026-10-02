<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Approvals\Concerns\OpensApprovalWithSupersede;
use Kanvas\Connectors\Intras\Actions\DiffPeopleWithIntrasAction;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;
use Kanvas\Connectors\Intras\Enums\PeopleIntrasSyncApprovalTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

/**
 * Runs on People::UPDATED. The SIPGO counterpart of Salesforce's RequestPeopleApprovalActivity, with
 * one difference: the request freezes the from → to change set in its payload, and the push writes
 * exactly that. An approver signs off on values they can see, and a SIPGO pull landing before the
 * decision cannot change what goes out.
 *
 * Because the payload is a snapshot, a newer edit supersedes a pending request by default — leaving
 * the old one blocking would push an outdated snapshot and silently drop the newer edit. The Rule can
 * still set `auto_reject_stale_pending: false`. A pending request whose snapshot equals the new one is
 * left alone, so re-saving an unchanged person does not churn approvals.
 */
#[WorkflowAction(
    name: 'IntrasRequestPeopleApprovalActivity',
    description: 'Opens approve_people_intras_update on a People linked to a SIPGO participant when its '
        . 'synced fields differ from SIPGO. The request carries the from → to change set the push will write.',
)]
class RequestPeopleApprovalActivity extends KanvasActivity implements WorkflowActivityInterface
{
    use OpensApprovalWithSupersede;

    public $tries = 3;

    /**
     * @param People $people
     */
    #[Override]
    public function execute(Model $people, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        if (! $people->get(CustomFieldEnum::INTRAS_PARTICIPANT_ID->value)) {
            return ['requested' => false, 'reason' => 'people is not linked to a SIPGO participant'];
        }

        return $this->executeIntegration(
            entity: $people,
            app: $app,
            integration: IntegrationsEnum::INTRAS,
            additionalParams: $params,
            integrationOperation: fn ($people, $app, $integrationCompany, $additionalParams) => $this->requestApproval(
                $people,
                $additionalParams,
            ),
            company: $people->company,
        );
    }

    private function requestApproval(People $people, array $params): array
    {
        $changes = new DiffPeopleWithIntrasAction($people)->execute();

        if ($changes === null) {
            return $this->failWorkflow([
                'requested' => false,
                'reason' => 'SIPGO participant ' . $people->get(CustomFieldEnum::INTRAS_PARTICIPANT_ID->value) . ' not found',
            ]);
        }

        if ($changes === []) {
            return ['requested' => false, 'reason' => 'SIPGO already matches'];
        }

        $approvalType = PeopleIntrasSyncApprovalTypeEnum::UPDATE->value;

        // `==`, not `===`: the payload is a MySQL JSON column, which stores object keys reordered.
        if (($people->pendingApproval($approvalType)?->payload['changes'] ?? null) == $changes) {
            return ['requested' => false, 'reason' => 'already pending'];
        }

        return $this->openOrSupersede(
            $people,
            $approvalType,
            [
                'people_id' => $people->getId(),
                'intras_participant_id' => (int) $people->get(CustomFieldEnum::INTRAS_PARTICIPANT_ID->value),
                'changes' => $changes,
            ],
            ['auto_reject_stale_pending' => true, ...$params],
        );
    }
}
