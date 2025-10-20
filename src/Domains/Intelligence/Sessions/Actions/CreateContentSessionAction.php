<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Sessions\Actions;

use Baka\Support\Str;
use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use Carbon\Exceptions\InvalidTimeZoneException;
use Exception;
use Illuminate\Support\Facades\Blade;
use InvalidArgumentException;
use Kanvas\ActionEngine\Engagements\Actions\CreateEngagementAction;
use Kanvas\ActionEngine\Engagements\DataTransferObject\Engagement;
use Kanvas\ActionEngine\Tasks\Repositories\TaskEngagementItemRepository;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session as DataTransferObjectSession;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Intelligence\Tools\CompanyIsHolidayTool;
use Kanvas\Intelligence\Tools\CompanyWorkHoursTool;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Variants\Models\Variants;
use RuntimeException;
use Yasumi\Exception\InvalidYearException;
use Yasumi\Exception\MissingTranslationException;
use Yasumi\Exception\ProviderNotFoundException;
use Yasumi\Exception\UnknownLocaleException;

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
        $channel = $lead->socialChannels()->orderBy('created_at', 'desc')->first();
        $lastMessage = $channel?->getLastMessage();
        $timezone = $lead->company->get('timezone') ?? 'UTC';
        $lastMessageTime = $lastMessage !== null ? Carbon::parse($lastMessage->created_at, $timezone)->toDateTimeString() : null;

        return array_merge(
            [
                'lead_id' => $lead->id,
                'lead_channel_id' => $lead->uuid,
                'type' => $lead->type?->name,
                'status' => $lead->status()->first()?->name,
                'company_timezone' => $lead->company->timezone,
                'kanvas_flow_state' => $lead->get('kanvas_flow_state'),
                'additional_context_information' => $lead->get(ConfigurationEnum::LEAD_CONTEXT_INFO->value) ?? [],
                'impersonate_email' => $lead->company->get('impersonate_email'),
                'last_message_time' => $lastMessageTime,
                'last_message' => $lastMessage,
                'intent_number' => $lead->get('intent_number') ?? 0,
            ],
            $this->mapPeople($lead->people, $lead),
            $lead->get(ConfigurationEnum::LEAD_CONTEXT_INFO->value) ?? []
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

        $similarRecommendedVehicles = [];
        $hasPotentialAdditionalVehicleInterest = false;
        if ($lead) {
            $data['leadOwnerEmail'] = $lead->owner?->email;
            $data['customerName'] = $people->name;
            $data['leadEmail'] = $people->getEmails()->first()?->value ?? '';
            $data['leadOwnerName'] = $lead->owner?->firstname . ' ' . $lead->owner?->lastname;
            $generalValueRole = $this->generateValuesForRole($lead);
            $data = array_merge($data, $generalValueRole);
            $similarRecommendedVehicles = $generalValueRole['similar_recommended_vehicles'] ?? [];
            $hasPotentialAdditionalVehicleInterest = $generalValueRole['has_potential_additional_vehicle_interest'] ?? false;
            //$checkList = $this->generateCheckListUrls();
            //$data = array_merge($data, $this->generateValuesForRole($lead));
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
            'check_list_status' => $this->getCheckListStatus($lead) ?? [],
            'similar_recommended_vehicles' => $similarRecommendedVehicles,
            'has_potential_additional_vehicle_interest' => $hasPotentialAdditionalVehicleInterest,
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

    /**
     * @todo make this general value for role general of product not vehicle
     * @throws InvalidFormatException
     * @throws RuntimeException
     * @throws InvalidYearException
     * @throws UnknownLocaleException
     * @throws ProviderNotFoundException
     * @throws InvalidArgumentException
     * @throws MissingTranslationException
     * @throws InvalidTimeZoneException
     */
    public function generateValuesForRole(Lead $lead): array
    {
        $additionalContext = $lead->get(ConfigurationEnum::LEAD_CONTEXT_INFO->value);
        $companyIsHoliday = (new CompanyIsHolidayTool($lead))->execute();
        $companyWorkHours = (new CompanyWorkHoursTool($lead))->execute();
        $vehicleInterest = $additionalContext['vehicle_interest'] ?? null;
        $relatedVehiclesOfPotentialInterest = $this->getRelatedVehicles($vehicleInterest ?? []);

        return [
            'company_name' => $lead->company->name,
            'branch_city' => $lead->company->branch->city,
            'branch_state' => $lead->company->branch->state,
            'branch_address' => $lead->company->branch->address . ' ' . $lead->company->branch->address2,
            'company_timezone' => $lead->company->get('timezone', 'UTC'),
            'lead_intent' => $additionalContext['lead_intent']['lead_intent'] ?? null,
            'completion_status' => $additionalContext['completion_status']['intent_completion_status'] ?? null,
            'holiday_status' => $companyIsHoliday['is_holiday'] ?? null,
            'work_hours_status' => $companyWorkHours['status'] ?? null,
            'next_open_iso' => $companyWorkHours['next_open_iso'] ?? null,
            'next_open_human' => $companyWorkHours['next_open_human'] ?? null,
            'salesperson_title' => $lead->owner?->firstname . ' ' . $lead->owner?->lastname,
            'customer_first_name' => $lead->people->firstname,
            'lead_email' => $lead->people->getEmails()->first()?->value ?? '',
            'kanvas_flow_state' => $lead->get('kanvas_flow_state'),
            'vehicle_interest' => $vehicleInterest ? $vehicleInterest['year'] . ' ' . $vehicleInterest['make'] . ' ' . $vehicleInterest['model'] : null,
            'has_potential_additional_vehicle_interest' => ! empty($relatedVehiclesOfPotentialInterest),
            'similar_recommended_vehicles' => $relatedVehiclesOfPotentialInterest,
        ];
    }

    protected function getRelatedVehicles(array $vehicleInterest): array
    {
        if (empty($vehicleInterest['make']) || empty($vehicleInterest['model'])) {
            return [];
        }

        $relatedVariant = Variants::searchByMultipleAttributes(
            app: $this->session->app,
            attributes: [
                ['name' => 'make', 'value' => $vehicleInterest['make'] ?? null],
                ['name' => 'model', 'value' => $vehicleInterest['model'] ?? null],
                //['name' => 'year', 'value' => $vehicleInterest['yearFrom'] ?? null],
            ],
            locale: 'en',
            user: null,
            company: $this->session->company,
        )->select('products_variants.uuid', 'products_variants.name')
            ->limit(10)
            ->orderBy('products_variants.name', 'desc')
            ->get();

        return $relatedVariant->toArray();
    }

    /**
     * @todo we need to combine both link and status
     * @throws InvalidArgumentException
     */
    protected function getCheckListStatus(Lead $lead): array
    {
        try {
            $checkList = $lead->get('check_list_status');
            $checkListId = $lead->company->get('default_checklist_id');
            if (isset($checkList['activeTaskListId'])) {
                $checkListId = $checkList['activeTaskListId'];
            }
            $checklistTaskCompleted = TaskEngagementItemRepository::getLeadsTaskItems($lead, $checkListId)->get();

            if ($checklistTaskCompleted->isEmpty()) {
                return [];
            }

            $checklist = [];
            foreach ($checklistTaskCompleted as $task) {
                if (empty($task->companyAction) || empty($task->companyAction->description)) {
                    continue;
                }

                $checklist[Str::camel((string) $task->companyAction->description)] = $task->status === 'completed' ? 'COMPLETED' : 'INCOMPLETE';
            }

            return $checklist;
        } catch (Exception $e) {
            report($e);

            return [];
        }
    }
}
