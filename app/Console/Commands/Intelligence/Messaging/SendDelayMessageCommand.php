<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence\Messaging;

use Baka\Support\Str;
use Baka\Traits\KanvasJobsTrait;
use Exception;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\ConfigurationEnum as CompanyConfigurationEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\DealerSocket\Enums\CustomFieldEnum as DealerSocketCustomFieldEnum;
use Kanvas\Connectors\DealerSocket\Services\DealerSocketWorkNoteService;
use Kanvas\Connectors\DriveCentric\Actions\AddCommentToDealAction;
use Kanvas\Connectors\DriveCentric\Enums\ConfigurationEnum as DriveCentricConfigurationEnum;
use Kanvas\Connectors\Elead\Entities\Lead as EntitiesLead;
use Kanvas\Connectors\Elead\Entities\SalesActivities;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Connectors\Twilio\Actions\StoreMessageSidAction;
use Kanvas\Connectors\VinSolution\Actions\PushNoteToLeadAction;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum as EnumsCustomFieldEnum;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadsEnumsConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Leads\Enums\AgentReachOutConfigEnum;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Services\DailyReportService;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Throwable;

class SendDelayMessageCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:intelligence:send-delay-message {app_id}';

    public function handle(): void
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $companies = Companies::getByCustomFieldBuilder(
            CompanyConfigurationEnum::MESSAGE_MINUTES_INTERVAL->value,
            null
        )->get()->keyBy('id');

        // Agent Reach Out uses 60 minutes as its fallback. Include companies
        // with pending delayed drafts even when they have no explicit legacy
        // delay setting, without changing how legacy first messages are found.
        $pendingCompanyIds = Message::fromApp($app)
            ->where('is_locked', 1)
            ->whereHas('tags', fn ($query) => $query->where('name', 'agent-reach-out-delayed'))
            ->distinct()
            ->pluck('companies_id');

        foreach ($pendingCompanyIds as $companyId) {
            if (! $companies->has($companyId)) {
                $companies->put($companyId, Companies::getById((int) $companyId));
            }
        }

        foreach ($companies as $company) {
            $this->newLine();
            $this->info('Processing company: ' . $company->name);

            $delayMinutes = $company->get(CompanyConfigurationEnum::MESSAGE_MINUTES_INTERVAL->value) ?? 60;
            $messages = $this->getLockedFirstMessages($app, $company, $delayMinutes);

            foreach ($messages as $message) {
                $this->processMessage($company, $message, $delayMinutes);
            }

            $this->info('Processed messages for company: ' . $company->name);
        }
    }

    protected function getLockedFirstMessages(Apps $app, Companies $company, int $delayMinutes): iterable
    {
        return Message::fromApp($app)
            ->fromCompany($company)
            ->where('is_locked', 1)
            ->whereHas(
                'messageType',
                fn ($query) => $query->whereIn('verb', [
                    'mailgun-email',
                    'twilio-sms',
                    'whatsapp-contact',
                    'whatsapp',
                    'whatsapp-text',
                    'whatsapp-image',
                ])
            )
            // Include messages from the previous day so a delay can cross
            // midnight, while avoiding an unbounded scan of abandoned drafts.
            ->where('created_at', '>=', now()->subMinutes($delayMinutes)->subDay())
            ->where('created_at', '<=', now()->subMinutes($delayMinutes))
            ->cursor();
    }

    protected function processMessage(Companies $company, Message $message, int $delayMinutes): void
    {
        try {
            $lead = $message->entity();
        } catch (Throwable $e) {
            report($e);
            $this->error('Message ID ' . $message->getId() . ' has an invalid Lead entity. Skipping.');
            $message->setUnlock();

            return;
        }

        if (! $lead instanceof Lead) {
            $this->info('Message ID ' . $message->getId() . ' is not linked to a Lead entity. Skipping.');
            $message->setUnlock();

            return;
        }

        $isAgentReachOut = $message->hasTag(['agent-reach-out']);
        $isDelayedAgentReachOut = $message->hasTag(['agent-reach-out-delayed']);
        $isLegacyFirstMessage = $message->hasTag(['first-message']) && ! $isAgentReachOut;

        if (! $isLegacyFirstMessage && ! $isDelayedAgentReachOut) {
            $this->info('Message ID ' . $message->getId() . ' does not have "first-message" tag. Skipping.');
            // A new reach-out may be locked for human approval rather than delay.
            // Leave that lock untouched; the approval workflow owns it.
            if (! $isAgentReachOut) {
                $message->setUnlock();
            }

            return;
        }

        $this->info('Processing lead name ' . $lead->people->name . ' for message ID ' . $message->getId());

        if ($lead->isAiMuted()) {
            $message->setUnlock();
            $lead->set(AgentReachOutConfigEnum::STATUS->value, AgentReachOutConfigEnum::STATUS_MUTED);
            $this->error('AI Mode OFF for Lead ID ' . $lead->getId());

            return;
        }

        if ($this->hasBeenContactedBySalesAgent($lead, $company)) {
            $message->setUnlock();
            $lead->set(AgentReachOutConfigEnum::STATUS->value, AgentReachOutConfigEnum::STATUS_SKIPPED);
            $lead->set(AgentReachOutConfigEnum::REASON->value, 'contacted_by_salesperson_before_delay');
            $this->info('Lead ID ' . $lead->getId() . ' has already been contacted by sales agent. Skipping.');

            return;
        }

        $messageContent = $isDelayedAgentReachOut
            ? (string) ($message->message['content'] ?? '')
            : (string) ($lead->get(LeadsEnumsConfigurationEnum::FIRST_MESSAGE->value) ?? '');
        if (empty($messageContent)) {
            $this->info('Lead ID ' . $lead->getId() . ' does not have a first message configured. Skipping.');
            $message->setUnlock();

            return;
        }

        if (! $this->sendCrmDelayNote($lead, $message, $company, $delayMinutes)) {
            return;
        }

        $this->sendDelayedMessage($lead, $message, $messageContent);
    }

    protected function hasBeenContactedBySalesAgent(Lead $lead, Companies $company): bool
    {
        $hasBeenContacted = $lead->hasBeenContacted();
        $isElead = $company->get(CustomFieldEnum::COMPANY->value) !== null;

        if (! $isElead) {
            return $hasBeenContacted;
        }

        if (empty($lead->get(CustomFieldEnum::OPPORTUNITY_ID->value))) {
            $this->info('Lead ID ' . $lead->getId() . ' does not have an Opportunity ID. Skipping.');

            return true;
        }

        try {
            return SalesActivities::hasSalesAgentReachedOut(
                $lead->app,
                $lead->company,
                $lead->get(CustomFieldEnum::OPPORTUNITY_ID->value)
            ) || $hasBeenContacted;
        } catch (Exception $e) {
            $this->error('Error checking sales activity for Lead ID ' . $lead->getId() . ': ' . $e->getMessage());

            return $hasBeenContacted;
        }
    }

    protected function sendCrmDelayNote(
        Lead $lead,
        Message $message,
        Companies $company,
        int $delayMinutes
    ): bool {
        if ($lead->get('delay_message_sent')) {
            return true;
        }

        $crmIntegration = $this->resolveCrmIntegration($company);
        $note = 'Sally sent the first message after the lead had been open for '
            . $delayMinutes . ' minutes with no contact from a sales agent.';

        try {
            $this->addDelayNoteToCrm($lead, $message, $crmIntegration, $note);
            $lead->set('delay_message_sent', true);

            return true;
        } catch (ClientException $e) {
            if (Str::contains($e->getMessage(), 'not active')
                || Str::contains($e->getMessage(), 'InactiveOpportunity')) {
                $lead->close();
                $this->info('Lead ID ' . $lead->getId() . ' opportunity is inactive. Closing lead.');
            } else {
                $this->error('Error adding CRM note for Lead ID ' . $lead->getId() . ': ' . $e->getMessage());
            }

            return false;
        } catch (Exception $e) {
            $this->error('Error adding CRM note for Lead ID ' . $lead->getId() . ': ' . $e->getMessage());

            return false;
        }
    }

    protected function sendDelayedMessage(Lead $lead, Message $message, string $messageContent): void
    {
        $communicationChannel = $message->get('communicationChannel');
        $fromNumber = $message->get('from_number');
        $title = $message->get('title');

        try {
            $providerResponse = new SendMessageToLeadAction($lead)->execute(
                $communicationChannel,
                $messageContent,
                $fromNumber,
                $title,
            );
            new StoreMessageSidAction($message)->execute($providerResponse);

            $message->setUnlock();
            $message->setPublic();
            $message->created_at = date('Y-m-d H:i:s');
            $message->saveOrFail();

            $lead->set(LeadsEnumsConfigurationEnum::SENT_FIRST_MESSAGE_AT->value, $message->created_at);
            if ($message->hasTag(['agent-reach-out-delayed'])) {
                $channelsSent = (array) $lead->get(AgentReachOutConfigEnum::CHANNELS_SENT->value);
                $channelsSent[] = $communicationChannel;
                $lead->set(
                    AgentReachOutConfigEnum::CHANNELS_SENT->value,
                    array_values(array_unique(array_filter($channelsSent))),
                );
                $lead->set(AgentReachOutConfigEnum::SENT_AT->value, $message->created_at);
                $lead->set(AgentReachOutConfigEnum::STATUS->value, AgentReachOutConfigEnum::STATUS_SENT);
            }
            $this->setPreferredChannel($lead, $message, $communicationChannel);

            $message->fireWorkflow(
                WorkflowEnum::CREATED->value,
                true,
                ['app' => $message->app]
            );

            $this->info('Sent delayed message for Lead ID ' . $lead->getId() . ' for message ID ' . $message->getId());

            DailyReportService::track($lead->app, $lead->company, 'ai_delayed_message_sent');
        } catch (Exception $e) {
            $this->error('Error sending message for Lead ID ' . $lead->getId() . ': ' . $e->getMessage());
        }
    }

    protected function setPreferredChannel(
        Lead $lead,
        Message $message,
        string $communicationChannel
    ): void {
        // if (! $lead->get(LeadsEnumsConfigurationEnum::PREFERRED_CHANNEL->value)) {
        //     $lead->set(LeadsEnumsConfigurationEnum::PREFERRED_CHANNEL->value, $communicationChannel);
        // }

        // if ($lead->get(LeadsEnumsConfigurationEnum::GUILD_PREFERRED_CHANNEL_UUID->value)) {
        //     return;
        // }

        $communicationChannelNumber = $message->message['chat_jid'] ?? null;
        if (! $communicationChannelNumber) {
            return;
        }

        $channelSlug = SessionChannelService::createChannelSlug($communicationChannel, $communicationChannelNumber);
        $existingChannel = Channel::fromApp($lead->app)
            ->fromCompany($lead->company)
            ->where('slug', $channelSlug)
            ->first();

        if ($existingChannel) {
            $lead->set(
                LeadsEnumsConfigurationEnum::GUILD_PREFERRED_CHANNEL_UUID->value,
                $existingChannel->uuid
            );
        }
    }

    protected function resolveCrmIntegration(Companies $company): ?IntegrationsEnum
    {
        if ($company->get(CustomFieldEnum::COMPANY->value) !== null) {
            return IntegrationsEnum::ELEAD;
        }

        if ($company->get(EnumsCustomFieldEnum::COMPANY->value) !== null) {
            return IntegrationsEnum::VIN_SOLUTION;
        }

        if ($company->get(DealerSocketCustomFieldEnum::DEALER_SOCKET_CREDENTIAL->value) !== null) {
            return IntegrationsEnum::DEALERSOCKET;
        }

        if ($company->get(DriveCentricConfigurationEnum::STORE_ID->value) !== null) {
            return IntegrationsEnum::DRIVE_CENTRIC;
        }

        return null;
    }

    protected function addDelayNoteToCrm(
        Lead $lead,
        Message $message,
        ?IntegrationsEnum $crmIntegration,
        string $note
    ): mixed {
        return match ($crmIntegration) {
            IntegrationsEnum::ELEAD => tap(
                EntitiesLead::getById(
                    $lead->app,
                    $lead->company,
                    (string) $lead->get(CustomFieldEnum::OPPORTUNITY_ID->value)
                ),
                fn (EntitiesLead $eLeadOpportunity) => $eLeadOpportunity->addComment($note)
            ),
            IntegrationsEnum::VIN_SOLUTION => new PushNoteToLeadAction(
                lead: $lead,
                message: $message,
            )->execute($note),
            IntegrationsEnum::DEALERSOCKET => new DealerSocketWorkNoteService(
                $lead->app,
                $lead->company
            )->addSimpleNote($lead, $note),
            IntegrationsEnum::DRIVE_CENTRIC => new AddCommentToDealAction($lead)->execute($note),
            default => null,
        };
    }
}
