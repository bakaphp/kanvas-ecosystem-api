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
use Kanvas\ActionEngine\Engagements\Traits\GeneratesChecklistEngagementUrls;
use Kanvas\ActionEngine\Tasks\Repositories\TaskEngagementItemRepository;
use Kanvas\Companies\Enums\ConfigurationEnum as EnumsConfigurationEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadsEnumsConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\Services\LeadConfigurationService;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session as DataTransferObjectSession;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Intelligence\Tools\CompanyIsHolidayTool;
use Kanvas\Intelligence\Tools\CompanyWorkHoursTool;
use Kanvas\Intelligence\Tools\LeadIntentTool;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Users\Models\Users;
use RuntimeException;
use Throwable;
use Yasumi\Exception\InvalidYearException;
use Yasumi\Exception\MissingTranslationException;
use Yasumi\Exception\ProviderNotFoundException;
use Yasumi\Exception\UnknownLocaleException;

class CreateContentSessionAction
{
    use GeneratesChecklistEngagementUrls;

    protected Lead|People|Users $entity;

    public function __construct(
        protected Session|DataTransferObjectSession $session
    ) {
        /** @psalm-suppress PropertyTypeCoercion */
        $this->entity = match ($this->session->entity_namespace) {
            People::class => People::getByIdFromCompanyApp($this->session->entity_id, $this->session->company, $this->session->app),
            Lead::class => Lead::getByIdFromCompanyApp($this->session->entity_id, $this->session->company, $this->session->app),
            Users::class => Users::getById($this->session->entity_id),
        };
    }

    public function execute(): array
    {
        $result = match ($this->session->entity_namespace) {
            People::class => $this->mapPeople($this->entity),
            Lead::class => $this->mapLead($this->entity),
            Users::class => $this->mapUser($this->entity),
            default => [],
        };

        $result['background'] = $this->generateBackground($result);

        return $result;
    }

    protected function generateBackground(array $data): ?array
    {
        $role = $this->session->agent?->role;

        if (! is_array($role)) {
            return null;
        }

        $roleData = $this->entity instanceof Lead
            ? $this->generateValuesForRole($this->entity)
            : [];
        $data = array_merge($data, $roleData);

        return array_map(
            fn (mixed $section): ?array => $this->renderRoleSection($section, $data),
            $role
        );
    }

    /**
     * Agents store each role section (background/steps/output) either as a plain string
     * or as an array of lines (with nulls for blank lines). Render Blade variables on each
     * line and return the section as an array of rendered lines (null blank-line markers
     * preserved); a string section is split on newlines first. A null section stays null.
     */
    protected function renderRoleSection(mixed $section, array $data): ?array
    {
        if ($section === null) {
            return null;
        }

        $lines = is_array($section) ? array_values($section) : explode("\n", (string) $section);

        return array_map(
            fn (mixed $line): ?string => is_string($line) ? $this->renderTemplate($line, $data) : null,
            $lines
        );
    }

    /**
     * Blade fatals on a variable that was never defined (unlike a defined-but-null
     * variable, which renders empty). Seed every referenced variable so an unknown one
     * renders blank instead of leaving raw {{ }} syntax in the prompt the agent receives.
     * Fall back to the raw template only if rendering still throws for another reason.
     */
    protected function renderTemplate(string $template, array $data): string
    {
        preg_match_all('/\$([a-zA-Z_]\w*)/', $template, $matches);

        foreach ($matches[1] as $variableName) {
            if (! array_key_exists($variableName, $data)) {
                $data[$variableName] = '';
            }
        }

        try {
            return Blade::render($template, $data);
        } catch (Throwable $e) {
            report($e);

            return $template;
        }
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
                'max_intent_number' => $lead->pipeline?->stages->count() ?? 0,
                'company_language' => $lead->company->get('lang', 'en'),
                'is_service_lead' => $lead->get('is_service_lead') ?? 0,
                'guild_first_message' => $lead->get(LeadsEnumsConfigurationEnum::FIRST_MESSAGE->value) ?? null,
                'ai_mode' => $lead->get(new LeadConfigurationService()->getAiModeKey($lead)),
                'follow_up_mode' => $lead->get(IntelligenceModeEnum::AI_FOLLOW_UP->value),
                'allow_call_appointments' => $lead->company->get(EnumsConfigurationEnum::ALLOW_CALL_APPOINTMENTS->value) ?? true,
                'work_hours' => $lead->company->get('work_hours'),
                'working_holiday_days' => $lead->company->get('working_holiday_days'),
                'notes_channel_slug' => $lead->notes?->slug,
            ],
            $this->mapPeople($lead->people, $lead),
            $lead->get(ConfigurationEnum::LEAD_CONTEXT_INFO->value) ?? []
        );
    }

    protected function mapPeople(People $people, ?Lead $lead = null): array
    {
        $checkList = $lead !== null ? $this->generateChecklistEngagementUrls($lead) : [];
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

        $result = [
            'branch' => $this->session->company->branch,
            'people_id' => $people->id,
            'firstname' => $people->firstname,
            'lastname' => $people->lastname,
            'middlename' => $people->middlename,
            'inventory_channel' => Channels::getDefault($people->company, $people->app)?->uuid,
            'leads' => [LeadsRepository::getPeopleActiveLead($people)?->toArray()],
            'address' => $people->address->toArray(),
            'contacts' => $people->contacts->toArray(),
            'checklist' => $checkList,
            'check_list_status' => $this->getCheckListStatus($lead) ?? [],
            'similar_recommended_vehicles' => $similarRecommendedVehicles,
            'has_potential_additional_vehicle_interest' => $hasPotentialAdditionalVehicleInterest,
            'leadEmail' => $data['leadEmail'],
            'leadOwnerName' => $data['leadOwnerName'],
            'leadOwnerEmail' => $data['leadOwnerEmail'],
        ];

        return array_merge($data, $result);
    }

    protected function mapUser(Users $user): array
    {
        $company = $user->getCurrentCompany();
        $branch = $company->branch;

        return [
            'user_id' => $user->id,
            'firstname' => $user->firstname,
            'lastname' => $user->lastname,
            'email' => $user->email,
            'company_name' => $company->name,
            'branch' => $branch,
            'branch_city' => $branch?->city,
            'branch_state' => $branch?->state,
            'branch_address' => $branch ? ($branch->address . ' ' . $branch->address2) : null,
            'company_timezone' => $company->get('timezone', 'UTC'),
        ];
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
        $intentTool = new LeadIntentTool($lead);
        $leadIntent = $intentTool->execute();

        return [
            'company_name' => $lead->company->name,
            'branch_city' => $lead->company->branch->city,
            'branch_state' => $lead->company->branch->state,
            'branch_address' => $lead->company->branch->address . ' ' . $lead->company->branch->address2,
            'company_timezone' => $lead->company->get('timezone', 'UTC'),
            'lead_intent' => $additionalContext['lead_intent']['lead_intent'] ?? $leadIntent['lead_intent'] ?? null,
            'completion_status' => $additionalContext['completion_status']['intent_completion_status'] ?? $leadIntent['intent_completion_status'] ?? null,
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

    public function getRelatedVehicles(array $vehicleInterest, int $limit = 10): array
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
            ->limit($limit)
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
