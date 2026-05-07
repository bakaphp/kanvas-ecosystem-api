<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerSocket\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Carbon\Carbon;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\DealerSocket\DataTransferObject\Lead as DataTransferObjectLead;
use Kanvas\Connectors\DealerSocket\Enums\ConfigurationEnum;
use Kanvas\Connectors\DealerSocket\Enums\CustomFieldEnum;
use Kanvas\Connectors\DealerSocket\Services\DealerSocketLeadService;
use Kanvas\Guild\Leads\Actions\SyncLeadByThirdPartyCustomFieldAction;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadsEnumsConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Enums\WorkflowEnum;

class PullLeadAction
{
    public function __construct(
        protected AppInterface $app,
        protected Companies $company,
        protected UserInterface $user,
    ) {
    }

    public function execute(
        ?Lead $lead = null,
        string|int|null $customerId = null,
        bool $triggerFirstMessage = false
    ): array {
        $customerId = $customerId ?? $lead?->getCustomFieldValue(CustomFieldEnum::DEALER_SOCKET_CUSTOMER_ID->value);

        if ($customerId === null) {
            return [];
        }
        $leadService = new DealerSocketLeadService($this->app, $this->company);

        $response = $leadService->getLeadByCustomerId($customerId);

        $response['company'] = $this->company;
        $currentLead = ! empty($response['events']) ? end($response['events']) : [];

        $dealerSocketLead = DataTransferObjectLead::from(
            $this->user,
            $this->app,
            $response
        );

        $lead = new SyncLeadByThirdPartyCustomFieldAction($dealerSocketLead)->execute();

        //set communication channel
        if ($lead->company->get('ai', false) || $triggerFirstMessage) {
            $this->setCommunicationChannel(
                $lead,
                $currentLead ?? []
            );
        }

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

    protected function setCommunicationChannel(Lead $lead, array $currentLead): void
    {
        //get a new fresh lead instance to avoid any issues with workflow state (disabled)
        $lead = Lead::getById($lead->id);
        $createdAt = $currentLead['insertDate'] ?? null;
        $createdAt = $currentLead['insertDate'] ?? null;

        $leadTypeName = (string) $lead->type?->name;

        if (empty($createdAt)
            || empty($lead->firstname)
            || strtolower($lead->firstname) === 'name'
            || $lead->get(LeadsEnumsConfigurationEnum::AGENT_COMMUNICATION_CHANNEL->value)
            || ! in_array(strtolower($leadTypeName), ['internet'])
            || ! $this->isWithin10Minutes($createdAt)
            || ! $lead->isActive()) {
            return;
        }

        $lead->set('process_via_pull', true);
        $lead->set('downloaded_from_dealer_socket', true);
        $lead->set('dealer_socket_date_in', $createdAt);

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
                'company' => $lead->company,
            ]
        );

        $lead->set('lead_first_contacted_at', Carbon::now()->toDateTimeString());
    }

    protected function isWithin10Minutes(string $dateString): bool
    {
        $diffTime = $this->company->get(ConfigurationEnum::LEAD_TIME_DIFF_MINUTES->value, 5) ?? 5;
        $leadTimezone = $this->company->get('timezone', 'America/New_York') ?? $this->company->timezone ?? 'America/New_York';

        $leadDate = Carbon::parse($dateString)->setTimezone($leadTimezone);
        $now = Carbon::now($leadTimezone);

        return $leadDate->diffInMinutes($now) <= $diffTime && $leadDate->isPast();
    }
}
