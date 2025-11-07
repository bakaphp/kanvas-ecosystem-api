<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Carbon\Carbon;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Facades\DB;
use Kanvas\ActionEngine\Tasks\Models\TaskList;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\Connectors\VinSolution\DataTransferObject\Lead as DataTransferObjectLead;
use Kanvas\Connectors\VinSolution\DataTransferObject\People;
use Kanvas\Connectors\VinSolution\Dealers\Dealer;
use Kanvas\Connectors\VinSolution\Dealers\User;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum;
use Kanvas\Connectors\VinSolution\Exceptions\VinSolutionException;
use Kanvas\Connectors\VinSolution\Leads\Contact;
use Kanvas\Connectors\VinSolution\Leads\Lead;
use Kanvas\Connectors\VinSolution\Vehicles\Interest;
use Kanvas\Connectors\VinSolution\Vehicles\TradeIn;
use Kanvas\Guild\Customers\Actions\SyncPeopleByThirdPartyCustomFieldAction;
use Kanvas\Guild\Leads\Actions\SyncLeadByThirdPartyCustomFieldAction;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadsEnumsConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead as ModelsLead;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Throwable;

class PullLeadAction
{
    public function __construct(
        protected AppInterface $app,
        protected Companies $company,
        protected UserInterface $user,
    ) {
    }

    public function execute(?ModelsLead $lead = null, ?int $leadId = null): array
    {
        return DB::transaction(function () use ($lead, $leadId) {
            $vinCompany = Dealer::getById($this->company->get(ConfigurationEnum::COMPANY->value), $this->app);

            $vinUserId = $this->user->get(ConfigurationEnum::getUserKey($this->company, $this->user));
            $vinUserId = $this->user->get(ConfigurationEnum::getUserKey($this->company, $this->user));
            $vinLeadUserId = $lead !== null ? $lead->user->get(ConfigurationEnum::getUserKey($this->company, $lead->user)) : null;
            $vinLeadOwnerUserId = $lead !== null ? $lead->owner?->get(ConfigurationEnum::getUserKey($this->company, $lead->owner)) : null;

            if (empty($vinUserId) && empty($vinLeadUserId) && empty($vinLeadOwnerUserId)) {
                throw new VinSolutionException(
                    'User not found in VinSolution',
                );
            }

            $user = Dealer::getUser(
                $vinCompany,
                $vinUserId ?? $vinLeadUserId ?? $vinLeadOwnerUserId,
                $this->app,
            );

            $vinLead = Lead::getAll(
                $vinCompany,
                $user,
                [
                    'leadId' => $leadId === null ? $lead->get(CustomFieldEnum::LEADS->value) : $leadId,
                    'app' => $this->app,
                ]
            );

            if (! empty($vinLead['Leads'])) {
                $currentLead = $vinLead['Leads'][0];
                $vinLead = DataTransferObjectLead::fromVinLeadArray(
                    $currentLead,
                    $vinCompany,
                    $user,
                    $this->app,
                    $this->company,
                    $this->user
                );

                $lead = new SyncLeadByThirdPartyCustomFieldAction($vinLead)->execute();

                //set comunication channel
                $this->setCommunicationChannel(
                    $lead,
                    $currentLead['createdUtc'] ?? ''
                );

                //$lead->searchable();
                $this->addCoBuyerParticipant(
                    $vinCompany,
                    $user,
                    $lead,
                    $currentLead
                );

                $this->setVehicleOfInterest(
                    $vinCompany,
                    $user,
                    $lead,
                    $currentLead
                );

                $this->setTradeInVehicle(
                    $vinCompany,
                    $user,
                    $lead,
                    $currentLead
                );

                $lead->refresh();

                return [
                    [
                        'id' => $lead->id,
                        'uuid' => $lead->uuid,
                        'people_id' => $lead->people->id,
                        'firstname' => $lead->people->firstname,
                        'middlename' => $lead->people->middlename,
                        'lastname' => $lead->people->lastname,
                        'email' => $lead->people?->getEmails()->first()?->value,
                        'phone' => $lead->people?->getPhones()->first()?->value,
                        'status' => $lead->status()?->first()?->name,
                        'lead_type' => $lead->type?->name,
                        'owner' => $lead->owner?->name ,
                        'owner_id' => $lead->leads_owner_id,
                        'custom_fields' => $lead->getAllCustomFields(),
                        'rank' => 1,
                    ],
                ];
            }

            return [];
        });
    }

    private function isWithin10Minutes(string $dateString): bool
    {
        $diffTime = $this->company->get(ConfigurationEnum::LEAD_TIME_DIFF_MINUTES->value, 10) ?? 10;
        $leadTimezone = $this->company->get('timezone', 'America/New_York') ?? $this->company->timezone ?? 'America/New_York';

        $leadDate = Carbon::parse($dateString)->setTimezone($leadTimezone);
        $now = Carbon::now($leadTimezone);

        return $leadDate->diffInMinutes($now) <= $diffTime && $leadDate->isPast();
    }

    private function setCommunicationChannel(ModelsLead $lead, string $vinSolutionDateIn): void
    {
        if (empty($vinSolutionDateIn)
            || $lead->get(LeadsEnumsConfigurationEnum::AGENT_COMMUNICATION_CHANNEL->value)
            || ! $this->isWithin10Minutes($vinSolutionDateIn)
            || ! $lead->isActive()) {
            return;
        }

        $lead->set('process_via_pull', true);
        $lead->set('vin_solution_date_in', $vinSolutionDateIn);

        $hasEmail = $lead->people?->getEmails()->count() > 0;
        $hasCellPhone = $lead->people?->getCellPhones()->count() > 0;

        $agentNotificationChannel = match (true) {
            $hasEmail && $hasCellPhone => 'sms',
            $hasEmail => 'email',
            $hasCellPhone => 'sms',
            default => null,
        };

        if ($agentNotificationChannel === null) {
            return;
        }

        $lead->set(
            LeadsEnumsConfigurationEnum::AGENT_COMMUNICATION_CHANNEL->value,
            $agentNotificationChannel
        );

        $lead->fireWorkflow(
            WorkflowEnum::FAKE_CONTEXT->value,
            true,
            [
                'app' => $lead->app,
            ]
        );
    }

    private function addCoBuyerParticipant(
        Dealer $vinCompany,
        User $user,
        ModelsLead $lead,
        array $currentLead
    ): void {
        try {
            $vinCoBuyer = Lead::getCoBuyer(
                $vinCompany,
                $user,
                $currentLead['LeadId']
            );
            if ($vinCoBuyer && (int) $vinCoBuyer > 0) {
                //$coBuyerPeople = new Customers($this, (int) $vinCoBuyer);
                //$coBuyerPeople = $coBuyerPeople->transform();
                $customer = Contact::getById($vinCompany, $user, (int) $vinCoBuyer);
                $people = People::fromContact($customer, $lead->app, $lead->company, $lead->user);
                $peopleSync = new SyncPeopleByThirdPartyCustomFieldAction($people);
                $coBuyerPeople = $peopleSync->execute();

                $lead->addCoBuyerParticipant($coBuyerPeople);
            }
            //$lead->co_buyer_id = $coBuyerPeople->getId();
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function setVehicleOfInterest(
        Dealer $vinCompany,
        User $user,
        ModelsLead $lead,
        array $currentLead
    ): void {
        try {
            $vehicleOfInterest = current(Interest::getByLeadId(
                $vinCompany,
                $user,
                $currentLead['LeadId']
            )->items);

            if (
                ! empty($vehicleOfInterest) &&
                isset($vehicleOfInterest['year']) &&
                $vehicleOfInterest['year'] > 0
            ) {
                $inventoryType = strtolower($vehicleOfInterest['inventoryType'] ?? '');
                $isUnknown = $inventoryType === 'unknown';

                if (! $isUnknown) {
                    $vehicleOfInterest['isNew'] = $inventoryType === 'new';

                    if (! empty($vehicleOfInterest['autoEntity']['inventoryType'])) {
                        $vehicleOfInterest['isNew'] = strtolower($vehicleOfInterest['autoEntity']['inventoryType']) === 'n';
                    }
                }

                $lead->set(CustomFieldEnum::VEHICLE_OF_INTEREST->value, $vehicleOfInterest);
            }

            if (
                ! empty($vehicleOfInterest) &&
                $lead->company->get('enable_vehicle_checklist') &&
                isset($vehicleOfInterest['year']) &&
                $vehicleOfInterest['year'] > 0 &&
                isset($vehicleOfInterest['inventoryType'])
            ) {
                $isNew = strtolower($vehicleOfInterest['inventoryType']) === 'new' ? 1 : 0;
                $taskListNames = [
                    0 => 'Used Vehicle Checklist',
                    1 => 'New Vehicle Checklist',
                ];

                $taskList = TaskList::fromCompany($lead->companies)
                    ->fromApp($this->app)
                    ->where('name', $taskListNames[$isNew])
                    ->first();

                $checkListStatus = $lead->get('check_list_status');
                $canChangeStatus = empty($checkListStatus) || ($checkListStatus['mode'] ?? '') === 'automatic';

                $activeTaskListId = $taskList && $canChangeStatus
                    ? $taskList->getId()
                    : $lead->company->get('default_checklist_id');

                $lead->set('check_list_status', [
                    'mode' => 'automatic',
                    'activeTaskListId' => $activeTaskListId,
                ]);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function setTradeInVehicle(
        Dealer $vinCompany,
        User $user,
        ModelsLead $lead,
        array $currentLead
    ): void {
        try {
            $vehicleTradeIn = current(TradeIn::getByLeadId(
                $vinCompany,
                $user,
                $currentLead['LeadId']
            )->items);

            if (is_array($vehicleTradeIn) && count($vehicleTradeIn)) {
                $lead->set(LeadCustomFieldEnum::TRADE_IN->value, $vehicleTradeIn);
            }
        } catch (Throwable $e) {
            $message = $e->getMessage();

            $isIgnoredTradeInNotFound = $e instanceof ClientException
                && str_starts_with($message, 'Client error: `GET https://api.vinsolutions.com/vehicles/trade?leadId=')
                && str_contains($message, '`404 Not Found` response');

            if (! $isIgnoredTradeInNotFound) {
                report($e);
            }
        }
    }
}
