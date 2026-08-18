<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\ConfigurationEnum as CompaniesConfigurationEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\DealerSocket\Actions\PullPeopleAction;
use Kanvas\Connectors\DealerSocket\Enums\CustomFieldEnum as DealerSocketEnumsCustomFieldEnum;
use Kanvas\Connectors\DriveCentric\Actions\PullPeopleLeadAction;
use Kanvas\Connectors\DriveCentric\Enums\ConfigurationEnum;
use Kanvas\Connectors\Elead\Actions\PullLeadAction;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Connectors\Reynolds\Enums\ConfigurationEnum as ReynoldsConfigurationEnum;
use Kanvas\Connectors\Reynolds\Enums\CustomFieldEnum as ReynoldsCustomFieldEnum;
use Kanvas\Connectors\SalesAssist\Actions\CreateSocialChannelsAfterPullAction;
use Kanvas\Connectors\VinSolution\Actions\PullLeadAction as ActionsPullLeadAction;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum as EnumsCustomFieldEnum;
use Kanvas\Connectors\VinSolution\Services\ContactRejectionService;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Triggers\Actions\ApplyLeadClosingStatusAction;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;
use Throwable;

#[WorkflowAction(
    name: 'SalesAssist Pull Lead From CRM',
    description: 'Brings a lead INTO Kanvas from whichever CRM this company runs, pulling the person with it '
        . 'and opening the messaging channels. Inbound — the opposite direction to the push-lead steps. '
        . 'It dispatches on the company\'s configured CRM, so it is the one to use when you do not want '
        . 'to name a specific connector.',
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
            $lead = $this->findReynoldsLead(
                app: $app,
                company: $company,
                leadId: $leadId !== null ? (string) $leadId : null,
                email: $email,
                phone: $phone,
            );

            if ($lead !== null && $leadId !== null
                && (string) $lead->get(ReynoldsCustomFieldEnum::CLIENT_ID->value) !== (string) $leadId
            ) {
                $lead->set(ReynoldsCustomFieldEnum::CLIENT_ID->value, (string) $leadId);
            }

            $pullLead = $lead ? [$lead->toArray()] : [];
        }

        $resolvedLead = match (true) {
            $isDriveCentric => $leadModel ?? null,
            $isDealerSocket => isset($people) ? LeadsRepository::getPeopleActiveLead($people) : null,
            $isReynolds => $entity,
            $isVinSolutions, $isElead => isset($pullLead[0]['id'])
                ? Lead::getByIdFromCompanyApp((int) $pullLead[0]['id'], $company, $app)
                : null,
            default => null
        };

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
            }
        } catch (Throwable $e) {
            report($e);
        }

        return $pullLead;
    }

    private function findReynoldsLead(
        Apps $app,
        Companies $company,
        ?string $leadId,
        ?string $email,
        ?string $phone,
    ): ?Lead {
        if ($leadId === null && empty($email) && empty($phone)) {
            return null;
        }

        // Exclude terminal statuses instead of whitelisting "active" — Reynolds
        // dealers publish prospects as "Open" which the auto-seed creates, and
        // Kanvas defaults use "active" / "created". Exclusion covers both plus
        // any custom pipeline stage the dealer configures. MAPPING_STATUS_CRM
        // overrides the default when the company has a mapping installed.
        $closedStatuses = ['closed', 'sold', 'lost'];
        $mappingStatus = $company->get(CompaniesConfigurationEnum::MAPPING_STATUS_CRM->value);
        if (is_array($mappingStatus)
            && isset($mappingStatus['closed'])
            && is_array($mappingStatus['closed'])
            && ! empty($mappingStatus['closed'])
        ) {
            $closedStatuses = $mappingStatus['closed'];
        }

        $emailTypes = [
            ContactTypeEnum::EMAIL->value,
            ContactTypeEnum::PRIMARY_EMAIL->value,
            ContactTypeEnum::SECONDARY_EMAIL->value,
        ];
        $phoneTypes = [
            ContactTypeEnum::PHONE->value,
            ContactTypeEnum::CELLPHONE->value,
        ];

        return Lead::query()
            ->select('leads.*')
            ->join('peoples as p', 'p.id', '=', 'leads.people_id')
            ->join('peoples_contacts as pc', function ($join) {
                $join->on('pc.peoples_id', '=', 'p.id')
                    ->where('pc.is_deleted', 0);
            })
            ->join('leads_status as ls', 'ls.id', '=', 'leads.leads_status_id')
            // apps_custom_fields lives on the ecosystem connection (kanvas_laravel
            // DB) while leads / peoples / peoples_contacts live on crm. Prefix
            // the join with the resolved DB name so the same cross-DB pattern
            // HasCustomFields::getByCustomFieldBuilder uses keeps working here.
            ->leftJoin(
                DB::connection('ecosystem')->getDatabaseName() . '.apps_custom_fields as cf',
                function ($join) use ($company) {
                    $join->on('cf.entity_id', '=', 'leads.id')
                        ->where('cf.model_name', Lead::class)
                        ->where('cf.name', ReynoldsCustomFieldEnum::CLIENT_ID->value)
                        ->where('cf.companies_id', $company->getId())
                        ->where('cf.is_deleted', 0);
                },
            )
            ->where('leads.companies_id', $company->getId())
            ->where('leads.apps_id', $app->getId())
            ->where('leads.is_deleted', 0)
            ->whereNotIn('ls.name', $closedStatuses)
            ->where(function ($q) use ($leadId, $email, $phone, $emailTypes, $phoneTypes) {
                if ($leadId !== null) {
                    $q->orWhere('cf.value', $leadId);
                }
                if (! empty($email)) {
                    $q->orWhere(function ($sub) use ($email, $emailTypes) {
                        $sub->whereIn('pc.contacts_types_id', $emailTypes)
                            ->where('pc.value', $email);
                    });
                }
                if (! empty($phone)) {
                    $q->orWhere(function ($sub) use ($phone, $phoneTypes) {
                        $sub->whereIn('pc.contacts_types_id', $phoneTypes)
                            ->whereRaw('REGEXP_REPLACE(pc.value, "[^0-9]", "") = ?', [$phone]);
                    });
                }
            })
            // Prefer any lead whose CLIENT_ID custom field already matches the
            // inbound id; ties break by newest lead first. first() collapses
            // the JOIN's N-per-contact rows to the single top-ranked lead.
            ->orderByRaw('CASE WHEN cf.value = ? THEN 0 ELSE 1 END', [$leadId ?? ''])
            ->orderByDesc('leads.id')
            ->first();
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
