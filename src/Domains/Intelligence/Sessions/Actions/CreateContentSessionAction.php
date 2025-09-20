<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Sessions\Actions;

use Baka\Support\Str;
use Exception;
use Illuminate\Support\Facades\Blade;
use Kanvas\ActionEngine\Engagements\Actions\CreateEngagementAction;
use Kanvas\ActionEngine\Engagements\DataTransferObject\Engagement;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session as DataTransferObjectSession;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Intelligence\Tools\CompanyIsHolidayTool;
use Kanvas\Intelligence\Tools\CompanyWorkHoursTool;
use Kanvas\Inventory\Channels\Models\Channels;

class CreateContentSessionAction
{
    protected Lead|People $entity;

    public function __construct(
        protected Session|DataTransferObjectSession $session
    ) {
        /** @psalm-suppress PropertyTypeCoercion */
        $this->entity = match ($this->session->entity_namespace) {
            People::class => People::getByIdFromCompanyApp($this->session->entity_id, $this->session->company, $this->session->app),
            Lead::class => Lead::getByIdFromCompanyApp($this->session->entity_id, $this->session->company, $this->session->app),
        };
    }

    public function execute(): array
    {
        return match ($this->session->entity_namespace) {
            People::class => $this->mapPeople($this->entity),
            Lead::class => $this->mapLead($this->entity),
            default => [],
        };
    }

    protected function mapLead(Lead $lead): array
    {
        return array_merge(
            [
                'lead_id' => $lead->id,
                'lead_channel_id' => $lead->uuid,
                'type' => $lead->type?->name,
                'status' => $lead->status()->first()?->name,
                'company_timezone' => $lead->company->timezone,
                'kanvas_flow_state' => $lead->get('kanvas_flow_state'),
                'additional_context_information' => $lead->get(ConfigurationEnum::LEAD_CONTEXT_INFO->value) ?? [],
            ],
            $this->mapPeople($lead->people, $lead)
        );
    }

    protected function mapPeople(People $people, ?Lead $lead = null): array
    {
        $checkList = $this->generateCheckListUrls();
        $data = array_merge([
            'customerName' => null,
            'leadEmail' => null,
            'leadOwnerName' => null,
            'leadOwnerEmail' => null,
        ], $checkList);

        if ($lead) {
            $data['leadOwnerEmail'] = $lead->owner?->email;
            $data['customerName'] = $people->name;
            $data['leadEmail'] = $people->getEmails()->first()?->value ?? '';
            $data['leadOwnerName'] = $lead->owner?->firstname . ' ' . $lead->owner?->lastname;
            $data = array_merge($data, ...$this->generateValuesForRole($lead));
        }

        try {
            $background = $this->session->agent?->role !== null && is_array($this->session->agent->role) ? Blade::render(json_encode($this->session->agent->role), $data) : null;
        } catch (Exception $e) {
            report($e);
            $background = $this->session->agent?->role;
        }

        return [
            'branch' => $this->session->company->branch,
            'people_id' => $people->id,
            'firstname' => $people->firstname,
            'lastname' => $people->lastname,
            'middlename' => $people->middlename,
            'inventory_channel' => Channels::getDefault($people->company, $people->app)?->uuid,
            'leads' => $people->leads->toArray(),
            'address' => $people->address->toArray(),
            'contacts' => $people->contacts->toArray(),
            'background' => Str::isJson($background) ? json_decode($background) : $background,
            'checklist' => $checkList,
        ];
    }

    /**
     * @todo this has to be based on the checklist this agent is tied to
     */
    protected function generateCheckListUrls(): array
    {
        if ($this->entity instanceof People) {
            return [];
        }

        $actions = [
            'creditApp' => 'credit-app',
            'tradeIn' => 'add-trade',
        ];

        $results = [];

        foreach ($actions as $key => $action) {
            try {
                $engagement = new CreateEngagementAction(
                    Engagement::from(
                        $this->session->app,
                        $this->session->company,
                        $this->entity->user,
                        $this->entity,
                        [
                            'action' => $action,
                            'request_id' => Str::uuid()->toString(),
                            'source' => 'ai',
                            'status' => 'sent',
                            'data' => [],
                        ],
                        $this->entity->people
                    ),
                    false
                );
                $result = $engagement->execute();
                $results[$key] = $result->message->message['action_link'] ?? null;
            } catch (Exception $e) {
                //report($e);
                $results[$key] = null;
            }
        }

        return $results;
    }

    public function generateValuesForRole(Lead $lead): array
    {
        $additionalContext = $lead->get(ConfigurationEnum::LEAD_CONTEXT_INFO->value);
        $companyIsHoliday = (new CompanyIsHolidayTool($lead))->execute();
        $companyWorkHours = (new CompanyWorkHoursTool($lead))->execute();

        return [
            'company_name' => $lead->company->name,
            'branch_city' => $lead->company->branch->city,
            'branch_state' => $lead->company->branch->state,
            'branch_address' => $lead->company->branch->address . ' ' . $lead->company->branch->address2,
            'company_timezone' => $lead->company->get('timezone', 'UTC'),
            'lead_intent' => $additionalContext['lead_intent']['lead_intent'],
            'completion_status' => $additionalContext['completion_status']['intent_completion_status'],
            'holiday_status' => $companyIsHoliday['is_holiday'],
            'work_hours_status' => $companyWorkHours['status'],
            'next_open_iso' => $companyWorkHours['next_open_iso'],
            'next_open_human' => $companyWorkHours['next_open_human'],
            'salesperson_title' => $lead->owner?->firstname . ' ' . $lead->owner?->lastname,
            'customer_first_name' => $lead->people->firstname,
            'lead_email' => $lead->people->getEmails()->first()?->value ?? '',
        ];
    }
}
