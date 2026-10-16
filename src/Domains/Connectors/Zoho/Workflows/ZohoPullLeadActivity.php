<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Workflows;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\SalesAssist\Enums\ConfigurationEnum as SalesAssistConfigurationEnum;
use Kanvas\Connectors\Zoho\Actions\SyncZohoLeadAction;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Actions\CreateLeadSocialChannelsAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Triggers\Actions\ApplyLeadClosingStatusAction;
use Kanvas\Intelligence\Triggers\Enums\TriggersEnum;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;
use Throwable;

#[WorkflowAction(
    name: 'Zoho Pull Lead',
    description: 'Resolves a Zoho lead by zoho_id or entity_id. If found locally, returns it directly; '
        . 'otherwise pulls it from Zoho CRM, creates it with social channels, and triggers the AI workflow.',
    integration: IntegrationsEnum::ZOHO,
)]
class ZohoPullLeadActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        $company = $entity instanceof Lead
            ? $entity->company
            : Companies::getById($entity->companies_id ?? $params['company_id']);

        $zohoLeadId = (string) ($params['zoho_id'] ?? $params['entity_id'] ?? '');

        if ($zohoLeadId === '') {
            return ['error' => 'Zoho lead id is required', 'lead' => null];
        }

        $localLead = Lead::getByCustomFieldTransactionSafe(
            CustomFieldEnum::ZOHO_LEAD_ID->value,
            $zohoLeadId,
            $company,
        ) ?? Lead::getByCustomField(
            CustomFieldEnum::ZOHO_LEAD_ID->value,
            $zohoLeadId,
            $company,
        );

        if ($localLead instanceof Lead) {
            return ['lead' => $localLead->toArray(), 'synced' => false];
        }

        $receiver = ! empty($params['receiver_id'])
            ? LeadReceiver::getByIdFromCompany((int) $params['receiver_id'], $company)
            : LeadsRepository::getDefaultReceiver($company->defaultBranch);

        $lead = $this->syncLeadFromZoho($app, $company, $receiver, $zohoLeadId);

        if ($lead instanceof Lead) {
            try {
                $agentName = $company->get(SalesAssistConfigurationEnum::AGENT_NAME->value)
                    ?? SalesAssistConfigurationEnum::DEFAULT_AGENT_NAME;
                $agent = Agent::where('name', $agentName)
                    ->fromApp($app)
                    ->fromCompany($company)
                    ->notDeleted()
                    ->first() ?? Agent::fromApp($app)->fromCompany($company)->notDeleted()->first();

                if ($agent) {
                    new CreateLeadSocialChannelsAction(
                        $lead,
                        $app,
                        $params,
                        $agent->getId(),
                    )->execute();
                }

                new ApplyLeadClosingStatusAction($lead)->execute();

                if ($lead->get('ai_mode') === null) {
                    $lead->fireWorkflow(
                        WorkflowEnum::TRIGGER_AI->value,
                        true,
                        [
                            'app' => $lead->app,
                            'company' => $lead->company,
                            'trigger_type' => TriggersEnum::NEW_LEAD->value,
                        ]
                    );
                }
            } catch (Throwable $e) {
                report($e);
            }

            return ['lead' => $lead->toArray(), 'synced' => true];
        }

        return ['error' => 'Failed to sync lead from Zoho', 'lead' => null];
    }

    protected function syncLeadFromZoho(
        AppInterface $app,
        Companies $company,
        LeadReceiver $receiver,
        string $zohoLeadId
    ): ?Lead {
        return new SyncZohoLeadAction(
            $app,
            $company,
            $receiver,
            $zohoLeadId
        )->execute();
    }
}
