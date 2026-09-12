<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\DealerSocket\Actions\PullPeopleAction;
use Kanvas\Connectors\DealerSocket\Enums\CustomFieldEnum as DealerSocketEnumsCustomFieldEnum;
use Kanvas\Connectors\DriveCentric\Actions\PullPeopleLeadAction;
use Kanvas\Connectors\DriveCentric\Enums\ConfigurationEnum;
use Kanvas\Connectors\Elead\Actions\PullLeadAction;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Connectors\Reynolds\Actions\FindLeadCandidatesAction;
use Kanvas\Connectors\Reynolds\Enums\ConfigurationEnum as ReynoldsConfigurationEnum;
use Kanvas\Connectors\Reynolds\Enums\CustomFieldEnum as ReynoldsCustomFieldEnum;
use Kanvas\Connectors\SalesAssist\Actions\CreateSocialChannelsAfterPullAction;
use Kanvas\Connectors\VinSolution\Actions\PullLeadAction as ActionsPullLeadAction;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum as EnumsCustomFieldEnum;
use Kanvas\Connectors\VinSolution\Services\ContactRejectionService;
use Kanvas\Guild\Leads\Models\Lead;
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
    name: 'SalesAssist Pull Lead From CRM',
    description: 'Resolves the lead a CRM identifier or contact refers to, and opens the messaging channels '
        . 'for it. Inbound — the opposite direction to the push-lead steps. It dispatches on the '
        . 'company\'s configured CRM, so it is the one to use when you do not want to name a specific '
        . 'connector. For eLead, VinSolutions, DealerSocket and DriveCentric it calls the vendor and '
        . 'brings the lead and person INTO Kanvas. For Reynolds there is nothing to call — those '
        . 'prospects arrive by webhook — so it searches leads already here and returns the candidates '
        . 'ranked, without creating anything.',
    integration: IntegrationsEnum::SALESASSIST,
)]
class PullLeadActivity extends KanvasActivity implements WorkflowActivityInterface
{
    protected ?Companies $company = null;
    protected ?Apps $app = null;

    #[Override]
    /**
     * $entity <Lead>
     */
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        $isSync = $entity->id === 0;
        $company = Companies::getById($entity->companies_id);
        $this->company = $company;
        $this->app = $app;
        $leadId = $params['entity_id'] ?? null;
        $user = $params['user'] ?? null;
        $phone = $this->extractPhone($params['phone'] ?? null);
        $email = $params['email'] ?? null;

        $isElead = $company->get(CustomFieldEnum::COMPANY->value) !== null;
        $isVinSolutions = $company->get(EnumsCustomFieldEnum::COMPANY->value) !== null;
        $isDealerSocket = $company->get(DealerSocketEnumsCustomFieldEnum::DEALER_SOCKET_CREDENTIAL->value) !== null;
        $isDriveCentric = $company->get(ConfigurationEnum::STORE_ID->value) !== null;
        $isReynolds = $company->get(ReynoldsConfigurationEnum::REYNOLDS_DEALER_NUMBER->value) !== null;

        //$people = People::getByCustomFieldBuilder(CustomFieldEnum::PERSON_ID, $peopleId, )
        $pullLead = [];

        if ($isElead) {
            $pullLead = new PullLeadAction(
                $app,
                $company,
                $user
            )->execute($params, $entity->id > 0 ? $entity : null);
        } elseif ($isVinSolutions) {
            try {
                $pullLead = new ActionsPullLeadAction(
                    $app,
                    $company,
                    $user
                )->execute(
                    lead: $entity->id > 0 ? $entity : null,
                    leadId: (int) $leadId,
                );
            } catch (ClientException $e) {
                if (! ContactRejectionService::isRecordRejection($e)) {
                    throw $e;
                }

                return $this->failWorkflow([
                    'error' => 'VinSolution rejected the contact on pull',
                    'reason' => ContactRejectionService::recordForLead(
                        $entity instanceof Lead && $entity->getId() > 0 ? $entity : null,
                        $e
                    ),
                    'lead_id' => $entity->getId(),
                    'company_id' => $company->getId(),
                ]);
            }
        } elseif ($isDealerSocket) {
            $people = new PullPeopleAction(
                $app,
                $company,
                $user
            )->execute(
                email: $email,
                phoneNumber: $phone,
                customerId: $entity->id > 0 ? $entity->id : ((int) $leadId ?? null),
            );
            $pullLead = $people->toArray();
        } elseif ($isDriveCentric) {
            $leadModel = new PullPeopleLeadAction(
                $app,
                $company,
                $user
            )->execute(
                phone: $phone,
                email: $email,
            );

            $pullLead = $leadModel ? [$leadModel->toArray()] : [];
        } elseif ($isReynolds) {
            $pullLead = new FindLeadCandidatesAction($app, $company)->execute(
                clientId: $leadId !== null ? (string) $leadId : null,
                email: $email,
                phone: $phone,
                firstname: $params['firstname'] ?? null,
                lastname: $params['lastname'] ?? null,
            );
        }

        $resolvedLead = match (true) {
            $isDriveCentric => $leadModel ?? null,
            $isDealerSocket => isset($people) ? LeadsRepository::getPeopleActiveLead($people) : null,
            $isReynolds, $isVinSolutions, $isElead => isset($pullLead[0]['id'])
                ? Lead::getByIdFromCompanyApp((int) $pullLead[0]['id'], $company, $app)
                : null,
            default => null
        };

        // Stamping the inbound CRM id belongs to the caller's explicit attach, not
        // to a read — with several candidates this picks whichever ranked first,
        // which is the very ambiguity the picker exists to resolve. Kept for now
        // because dropping it is the behaviour change, and that needs product's
        // call; it now writes to the lead the pull actually resolved rather than
        // to the id=0 phantom.
        if ($isReynolds && $resolvedLead instanceof Lead && $leadId !== null
            && (string) $resolvedLead->get(ReynoldsCustomFieldEnum::CLIENT_ID->value) !== (string) $leadId
        ) {
            $resolvedLead->set(ReynoldsCustomFieldEnum::CLIENT_ID->value, (string) $leadId);
        }

        try {
            if ($resolvedLead instanceof Lead) {
                $agentName = $company->get('sales_assist_agent_name') ?? 'Sally';
                $agent = Agent::where('name', $agentName)
                    ->fromApp($app)
                    ->fromCompany($company)
                    ->notDeleted()
                    ->firstOrFail();

                new CreateSocialChannelsAfterPullAction(
                    $resolvedLead,
                    $app,
                    $params,
                    $agent->getId(),
                )->execute();

                new ApplyLeadClosingStatusAction($resolvedLead)->execute();

                if ($resolvedLead->get('ai_mode') == null) {
                    $resolvedLead->fireWorkflow(
                        WorkflowEnum::TRIGGER_AI->value,
                        true,
                        [
                            'app' => $resolvedLead->app,
                            'company' => $resolvedLead->company,
                            'trigger_type' => TriggersEnum::NEW_LEAD->value,
                        ]
                    );
                }
            }
        } catch (Throwable $e) {
            report($e);
        }

        return $pullLead;
    }

    private function extractPhone(mixed $phone): ?string
    {
        $raw = match (true) {
            $phone === null => null,
            is_string($phone) => $phone,
            is_array($phone) => match (true) {
                isset($phone['cell']) => (string) $phone['cell'],
                isset($phone['home']) => (string) $phone['home'],
                default => ($first = reset($phone)) !== false ? (string) $first : null,
            },
            default => (string) $phone,
        };

        if ($raw === null) {
            return null;
        }

        $sanitized = Str::sanitizePhoneNumber($raw);

        return $sanitized === '' ? null : $sanitized;
    }
}
