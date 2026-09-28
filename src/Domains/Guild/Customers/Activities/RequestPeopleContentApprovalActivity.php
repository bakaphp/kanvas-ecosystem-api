<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Approvals\Concerns\OpensApprovalWithSupersede;
use Kanvas\Guild\Customers\Enums\PeopleApprovalTypeEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;

/**
 * Runs on Lead::AFTER_RUNNING_RECEIVER. Opens `approve_people` — plain review of the lead's People in
 * Kanvas, independent of any external push. Deliberately carries no executeIntegration/integration
 * dependency: unlike the Salesforce sync gate (Connectors\Salesforce\Activities\RequestPeopleApprovalActivity),
 * reviewing a People's own content has nothing to do with any connector being configured, so a tenant
 * with no Salesforce (or any other CRM) integration set up can still gate this.
 *
 * Lives here (Customers) rather than under Leads because what it opens and gates is People, not the
 * Lead — the Lead is only the trigger that hands it a People to review. The entity it receives is a
 * Lead purely because that is what AFTER_RUNNING_RECEIVER fires on.
 */
#[WorkflowAction(name: 'RequestPeopleContentApprovalActivity')]
class RequestPeopleContentApprovalActivity extends KanvasActivity implements WorkflowActivityInterface
{
    use OpensApprovalWithSupersede;

    public $tries = 3;

    /**
     * @param Lead $lead
     */
    #[Override]
    public function execute(Model $lead, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        $people = $lead->people;

        if ($people === null) {
            return ['requested' => false, 'reason' => 'lead has no people'];
        }

        return $this->openOrSupersede(
            $people,
            PeopleApprovalTypeEnum::CONTENT->value,
            ['lead_id' => $lead->getId(), 'people_id' => $people->getId()],
            $params,
        );
    }
}
