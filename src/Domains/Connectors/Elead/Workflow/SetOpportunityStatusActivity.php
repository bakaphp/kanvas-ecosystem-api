<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Workflow;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\Actions\SyncLeadAction;
use Kanvas\Connectors\Elead\Entities\Lead as EleadLead;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction(
    name: 'Elead Set Opportunity Status',
    description: 'Updates status and subStatus of an Elead opportunity',
    integration: IntegrationsEnum::ELEAD,
)]
class SetOpportunityStatusActivity extends KanvasActivity
{
    public function execute(Model $entity, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        $lead = $this->resolveLead($entity);
        if (! $lead instanceof Lead) {
            return $this->failWorkflow([
                'error' => 'No Lead found for this entity',
                'entity_id' => $entity->getId(),
            ]);
        }

        $company = $lead->company;
        if (! $company instanceof Companies || ! $company->get(CustomFieldEnum::COMPANY->value)) {
            return $this->failWorkflow([
                'error' => 'Company not found in Elead',
                'lead_id' => $lead->getId(),
            ]);
        }

        $status = (string) ($params['status'] ?? 'Active');
        $subStatus = (string) ($params['subStatus'] ?? 'Engaged');

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::ELEAD,
            additionalParams: $params,
            integrationOperation: function (
                Model $entity,
                Apps $app,
                mixed $integrationCompany,
                array $additionalParams
            ) use ($lead, $company, $status, $subStatus): array {
                $opportunityId = $lead->get(CustomFieldEnum::OPPORTUNITY_ID->value);
                $opportunity = empty($opportunityId)
                    ? new SyncLeadAction($lead)->execute()
                    : EleadLead::getById($app, $company, (string) $opportunityId);

                $response = $opportunity->updateStatus($status, $subStatus);

                return [
                    'message' => 'Elead opportunity status updated successfully',
                    'lead_id' => $lead->getId(),
                    'opportunity_id' => $opportunity->id,
                    'status' => $status,
                    'subStatus' => $subStatus,
                    'response' => $response,
                ];
            },
            company: $company,
        );
    }

    private function resolveLead(Model $entity): ?Lead
    {
        if ($entity instanceof Lead) {
            return $entity;
        }

        if (! method_exists($entity, 'entity')) {
            return null;
        }

        $lead = $entity->entity(Lead::class);

        return $lead instanceof Lead ? $lead : null;
    }
}
