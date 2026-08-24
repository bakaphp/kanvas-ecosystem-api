<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Workflows;

use Baka\Support\Str;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Kanvas\ActionEngine\Pipelines\Models\Pipeline;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\ConfigurationEnum as CompanyConfigurationEnum;
use Kanvas\Connectors\Elead\Actions\AddOutBoundPhoneCallActivityToLeadAction;
use Kanvas\Connectors\Elead\Entities\Lead as EntitiesLead;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Connectors\Twilio\Actions\StoreMessageSidAction;
use Kanvas\Connectors\VoiceBridge\Enums\ConfigurationEnum as VoiceBridgeConfigurationEnum;
use Kanvas\Connectors\VoiceBridge\Jobs\LeadVoiceFollowUpJob;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadsEnumsConfigurationEnum;
use Kanvas\Guild\Leads\Exceptions\LeadMissingContactException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\ConfigurationEnum as EnumsConfigurationEnum;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\Leads\Actions\CreateLeadContextInfoAction;
use Kanvas\Intelligence\Leads\Actions\CreateLeadFirstEngagementMessageAction;
use Kanvas\Intelligence\Services\LeadConfigurationService;
use Kanvas\Intelligence\Sessions\Actions\CreateSessionAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Services\DailyReportService;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

/**
 * @deprecated Pre-kernel outbound-first orchestrator. Generates first-touch messages
 *   via template + CreateLeadFirstEngagementMessageAction — bypasses AgentChatKernel,
 *   so new backends added to the kernel don't automatically work here, no cross-channel
 *   memory rollup, dealer-specific code (Elead / VinSolution / hardcoded "Sally Takes Over")
 *   is baked in.
 *
 *   Stays running in prod (SalesAssist tenants depend on it). Do NOT modify behavior here.
 *   New outbound-first work belongs in the kernel-backed AgentReachOut* family — see
 *   src/Domains/Intelligence/Agents/CLAUDE.md for the canonical chat flow.
 *
 *   Remove this class once all tenants have been migrated to the new flow.
 */
#[WorkflowAction(
    name: 'Lead Agent First Message Outreach',
    description: 'Sends the agent\'s FIRST outbound message to a new lead. This CONTACTS the customer — wire '
        . 'it only to a trigger that means a genuinely new lead, or people get messaged twice.',
)]
class LeadAgentFirstMessageOutreachActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Lead $lead, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $lead,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            additionalParams: $params,
            integrationOperation: function ($lead, $app, $integrationCompany, $additionalParams) use ($params) {
                $leadAiMode = IntelligenceModeEnum::tryFrom((string) $lead->get(new LeadConfigurationService()->getAiModeKey($lead)));
                if ($leadAiMode?->isOff()) {
                    return [
                        'ai_mode is OFF',
                    ];
                }

                try {
                    $createContext = new CreateLeadContextInfoAction($lead)->execute($params);
                } catch (Exception $e) {
                    return $this->failWorkflow([
                        'error' => 'Error creating lead context: ' . $e->getMessage(),
                    ]);
                }

                $cellPhone = $lead->people->getCellPhones()->first()?->value ?? '';
                $email = $lead->people->getEmails()->first()?->value ?? '';
                $cellPhone = Str::normalizePhoneNumber($cellPhone);
                $source = $lead->source?->name ?? '';

                // Dealertrack sends its own first touch, so ours would be a duplicate to the customer.
                if (in_array(strtolower($source), ['dealertrack'])) {
                    return $this->failWorkflow([
                        'error' => 'Lead source is ' . $source . ', skipping first message outreach.',
                    ]);
                }

                if (! $cellPhone && ! $email) {
                    return $this->failWorkflow([
                        'error' => 'Lead does not have a phone number or email, wont be able to send message until we add email support',
                    ]);
                }

                $channelOrder = $lead->company->get(
                    CompanyConfigurationEnum::CHANNEL_ORDER->value
                ) ?? ['sms', 'email'];

                $availableChannels = [
                    'sms' => $cellPhone,
                    'email' => $email,
                ];

                $channels = [];
                foreach ($channelOrder as $ch) {
                    if (isset($availableChannels[$ch])) {
                        $channels[$ch] = $availableChannels[$ch];
                    }
                }

                $stageConfig = $lead->getCurrentPipelineStage()->config['notification_engagement_rules'];
                $workingHoursDefaultMode = $lead->company->get(CompanyConfigurationEnum::AI_WORKING_HOURS_DEFAULT_MODE->value);
                $disableSending = false;

                if ($workingHoursDefaultMode !== null) {
                    try {
                        $isWithinWorkingHours = $lead->company->isWithinWorkingHours(now());
                    } catch (InvalidArgumentException $e) {
                        $isWithinWorkingHours = false;
                    }

                    if ($isWithinWorkingHours) {
                        $lead->set(new LeadConfigurationService()->getAiModeKey($lead), $workingHoursDefaultMode);
                    }
                }

                $currentAiMode = IntelligenceModeEnum::tryFrom((string) $lead->get(new LeadConfigurationService()->getAiModeKey($lead)));
                $disableSending = $currentAiMode?->isOff() ?? false;

                $leadType = $lead->type()->first();
                $firstMessageDefaultKey = new LeadConfigurationService()->getFirstMessageDefaultKey($lead);
                $leadTypeConfig = $leadType?->config ?? [];

                if (isset($leadTypeConfig[$firstMessageDefaultKey]) && ! $leadTypeConfig[$firstMessageDefaultKey]) {
                    $disableSending = true;
                }

                if ($lead->hasBeenContacted()) {
                    $disableSending = true;
                }

                $totalSentMessages = 0;
                $stopTheClockIteration = 0;
                $sentChannels = [];
                $stopTheClock = false;

                foreach ($channels as $communicationChannel => $value) {
                    if ($value === null || empty($value)) {
                        continue;
                    }
                    $template = $stageConfig['templates'][$communicationChannel] ?? null;

                    if ($template === null || empty($template)) {
                        continue;
                    }
                    $firstLeadMessage = new CreateLeadFirstEngagementMessageAction($lead, $template)->execute();

                    $leadContext = $lead->get(EnumsConfigurationEnum::LEAD_CONTEXT_INFO->value);
                    $leadContext['first_message'] = $firstLeadMessage;
                    $lead->set(EnumsConfigurationEnum::LEAD_CONTEXT_INFO->value, $leadContext);
                    $lead->set(LeadsEnumsConfigurationEnum::FIRST_MESSAGE->value, $firstLeadMessage['message']);

                    $communicationChannelNumber = match ($communicationChannel) {
                        'sms' => $cellPhone,
                        'email' => $email,
                        'whatsapp' => $cellPhone,
                        default => $cellPhone
                    };

                    if (empty($communicationChannelNumber)) {
                        return $this->failWorkflow([
                            'error' => 'Lead does not have a phone number or email for channel ' . $communicationChannel . ', wont be able to send message until we add email support',
                        ]);
                    }

                    if (isset($params['create_session'])) {
                        $channel = ChannelDto::from([
                            'apps' => $app,
                            'companies' => $lead->company,
                            'users' => $lead->user,
                            'entity_id' => $lead->getId(),
                            'entity_namespace' => Lead::class,
                            'name' => ucwords($communicationChannel) . ' ' . $lead->getId(),
                            'slug' => SessionChannelService::createChannelSlug(
                                $communicationChannel,
                                $communicationChannelNumber
                            ),
                        ]);
                        $channel = new CreateChannelAction($channel)->execute();

                        $sessionDto = Session::from([
                            'agent' => Agent::getById($params['agent_id']),
                            'channel' => $channel,
                            'app' => $app,
                            'company' => $lead->company,
                            'entity_id' => $lead->getId(),
                            'entity_namespace' => Lead::class,
                            'user' => $lead->user->toArray(),
                            'canal_id' => SessionChannelService::createCanalId(
                                $communicationChannel,
                                $communicationChannelNumber
                            ),
                        ]);
                        new CreateSessionAction($sessionDto)->execute();
                    }

                    //hijack session
                    if (
                        $lead->company->get('allow_session_hijack', false)
                        && $lead->company->get('overwrite_phone_number') !== null
                    ) {
                        $overwriteConfig = $lead->company->get('overwrite_phone_number');
                        $overwriteConfig = array_flip($overwriteConfig);
                        $originalRemoteJid = match ($communicationChannel) {
                            'whatsapp' => $cellPhone = $cellPhone . '@s.whatsapp.net',
                            'sms' => '+' . $cellPhone,
                            'email' => SessionChannelService::createChannelSlug('email', $email),
                        };

                        if (isset($overwriteConfig[$originalRemoteJid])) {
                            unset($params['disable_sending']);
                        }
                    }

                    //send the first message
                    if (! isset($params['disable_sending']) && ! $disableSending) {
                        $leadCurrentDateIn = $this->getLeadCreatedAt($lead);

                        $messageType = match ($communicationChannel) {
                            'sms' => 'twilio-sms',
                            'email' => 'mailgun-email',
                            'whatsapp' => 'whatsapp',
                            'voice' => 'voice',
                            default => 'twilio-sms',
                        };
                        $skipLeadCurrentDatIn = isset($params['skipLeadCurrentDatIn']) && $params['skipLeadCurrentDatIn'];

                        if ($skipLeadCurrentDatIn || ($leadCurrentDateIn && $this->isWithinOneDay($lead, $leadCurrentDateIn))) {
                            try {
                                //$shouldSendFirstMessageNow = $this->shouldSendFirstMessageNow($lead) && $template !== null; //to discuss again
                                $shouldSendFirstMessageNow = $this->shouldSendFirstMessageNow($lead);

                                $createMessage = $this->createMessage(
                                    $lead,
                                    $firstLeadMessage['message'],
                                    $communicationChannelNumber,
                                    $messageType,
                                    $shouldSendFirstMessageNow
                                );

                                $sentChannels[] = $communicationChannel;
                                $totalSentMessages++;

                                if ($shouldSendFirstMessageNow) {
                                    $providerResponse = new SendMessageToLeadAction($lead)->execute(
                                        $communicationChannel,
                                        $firstLeadMessage['message'],
                                        $params['from'] ?? null,
                                        $firstLeadMessage['title'] ?? null,
                                    );
                                    new StoreMessageSidAction($createMessage)->execute($providerResponse);

                                    $this->addMessageToChannel($createMessage, $channel ?? null, $lead);

                                    $stopTheClock = true;
                                    $lead->set(LeadsEnumsConfigurationEnum::SENT_FIRST_MESSAGE_AT->value, date('Y-m-d H:i:s'));
                                    $lead->set('title_email_follow_up', $firstLeadMessage['title'] ?? null);
                                    $sentChannels[] = $communicationChannel;
                                    $totalSentMessages++;

                                    DailyReportService::track(
                                        $app,
                                        $lead->company,
                                        'ai_messages_sent'
                                    );
                                } else {
                                    $createMessage->setLock();
                                    $createMessage->setPrivate();
                                    $createMessage->set('communicationChannel', $communicationChannel);
                                    $createMessage->set('from_number', $params['from'] ?? null);
                                    $createMessage->set('title', $firstLeadMessage['title'] ?? null);

                                    $stopTheClock = false;
                                    $this->addMessageToChannel($createMessage, $channel ?? null, $lead);

                                    DailyReportService::track(
                                        $app,
                                        $lead->company,
                                        'ai_delayed_message_scheduled'
                                    );
                                }

                                //only do the external activity once for the first message
                                if ($shouldSendFirstMessageNow && $stopTheClockIteration === 0 && $stopTheClock) {
                                    $outBoundPhoneCallActivity = $this->leadExternalActivityDateIn($lead, $createMessage);
                                    $stopTheClockIteration++;
                                }
                            } catch (LeadMissingContactException $e) {
                                Log::info('Skipped first message outreach: ' . $e->getMessage(), [
                                    'lead_id' => $lead->getId(),
                                    'channel' => $communicationChannel,
                                ]);
                            } catch (Exception $e) {
                                report($e);
                            }
                        }
                    }
                }

                $timezone = $lead->company->get('timezone') ?? 'UTC';
                $now = Carbon::now($timezone);
                if (! isset($firstLeadMessage)) {
                    return $this->failWorkflow([
                        'message' => 'First message no generate',
                        'channels' => $channels,
                    ]);
                }
                $lead->set(EnumsConfigurationEnum::LAST_MESSAGE_TIME->value, $now->toDateTimeString());
                $lead->set(EnumsConfigurationEnum::LAST_MESSAGE->value, $firstLeadMessage);

                $lead->set('intent_number', $lead->get('intent_number') ?? 0 + 1);

                $canRunVoice = $lead->get(VoiceBridgeConfigurationEnum::API_KEY->value, 0)
                    ?? $lead->company->get(VoiceBridgeConfigurationEnum::API_KEY->value, 0);
                //?? $app->get(VoiceBridgeConfigurationEnum::API_KEY->value, 0);

                if ($totalSentMessages > 0 && ! empty($canRunVoice)) {
                    $delayMinutes = (int) (
                        ($stageConfig['voice_no_response_minutes'] ?? null)
                        ?? $app->get(VoiceBridgeConfigurationEnum::VOICE_NO_RESPONSE_MINUTES->value)
                        ?? 15
                    );

                    if ($delayMinutes > 0) {
                        LeadVoiceFollowUpJob::dispatch($lead, $app)
                            ->delay(now()->addMinutes($delayMinutes));
                    }
                }

                //move to stage 2 of the pipeline
                $lead->moveToNextPipelineStage();

                return [
                    'context' => $createContext,
                    'first_message' => $firstLeadMessage,
                    'outbound_call_activity' => $outBoundPhoneCallActivity ?? null,
                    'lead_current_date_in' => $leadCurrentDateIn ?? null,
                    'is_today' => (int) $this->isWithinOneDay($lead, $leadCurrentDateIn ?? ''),
                    'lead_opportunity' => $eLeadOpportunity ?? null,
                    'message_id' => isset($createMessage) ? $createMessage->getId() : null,
                    'total_sent_messages' => $totalSentMessages,
                    'sent_channels' => $sentChannels,
                    //'double_check_is_internet' => $doubleCheckIsInternet ?? null,
                ];
            }
        );
    }

    private function shouldSendFirstMessageNow(Lead $lead): bool
    {
        $aiMode = IntelligenceModeEnum::tryFrom((string) $lead->get(new LeadConfigurationService()->getAiModeKey($lead)));
        if ($aiMode?->isOff()) {
            return false;
        }

        try {
            $isWithinWorkingHours = $lead->company->isWithinWorkingHours(now());
        } catch (InvalidArgumentException $e) {
            $isWithinWorkingHours = false;
        }

        if (! $isWithinWorkingHours) {
            return true;
        } elseif ($lead->get(new LeadConfigurationService()->getAiModeKey($lead)) === IntelligenceModeEnum::SUPPORT->value) {
            return false;
        } else {
            return true;
        }
        // $company = $lead->company;

        // // If company does NOT enforce the rule "send only during off-hours",
        // // we can always send.
        // if (! $company->get(EnumsConfigurationEnum::FIRST_MESSAGE_ONLY_DURING_OFF_BUSINESS_HOURS->value, false)) {
        //     return true;
        // }

        // // Rule *is enabled*: allow only outside business hours.
        // return ! $company->isWithinWorkingHours(now());
    }

    private function getLeadCreatedAt(Lead $lead): ?string
    {
        $leadCurrentDateIn = null;
        $opportunityId = (string) $lead->get(CustomFieldEnum::OPPORTUNITY_ID->value);
        if ($lead->company->get(CustomFieldEnum::COMPANY->value) && ! empty($opportunityId)) {
            $eLeadOpportunity = EntitiesLead::getById($lead->app, $lead->company, $opportunityId);
            $leadCurrentDateIn = (string) $eLeadOpportunity->dateIn;
        } elseif ($lead->get('downloaded_from_vin_solution')) {
            $leadCurrentDateIn = (string) $lead->get('vin_solution_date_in');
        }

        return $leadCurrentDateIn;
    }

    /**
     *  @todo this is not the right place to do this but for now its ok
     * we need to make sure we have the phone call activity
     */
    private function leadExternalActivityDateIn(Lead $lead, Message $message): mixed
    {
        $outBoundPhoneCallActivity = null;
        if ($lead->get('downloaded_from_eleads')) {
            $outBoundPhoneCallActivity = new AddOutBoundPhoneCallActivityToLeadAction($lead, $message)
            ->execute('Sally Takes Over', 'Sally stops the clock');
        } elseif ($lead->get('downloaded_from_vin_solution')) {
            // To do VinSolution Push Note to Lead
        }

        return $outBoundPhoneCallActivity;
    }

    private function isWithinOneDay(Lead $lead, string $dateString): bool
    {
        $leadTimezone = $lead->company->get('timezone', 'America/New_York') ?? $lead->company->timezone ?? 'America/New_York';

        $leadDate = Carbon::parse($dateString)->setTimezone($leadTimezone);
        $now = Carbon::now($leadTimezone);

        return $leadDate->diffInHours($now) <= 24 && $leadDate->isPast();
    }

    private function createMessage(
        Lead $lead,
        string $text,
        string $to,
        string $messageType = 'twilio-sms',
        bool $runWorkflow = true,
    ): Message {
        $user = $lead->company->getAiAgentUser() ?? $lead->user;

        $messageTypeModel = new CreateMessageTypeAction(
            new MessageTypeInput(
                $lead->app->getId(),
                0,
                $messageType,
                $messageType,
            )
        )->execute();

        $messageInput = new MessageInput(
            app: $lead->app,
            company: $lead->company,
            user: $user,
            type: $messageTypeModel,
            message: [
                    'content' => $text,
                    'raw_data' => $text,
                    'message_id' => '--',
                    'chat_jid' => $to,
                    'from_me' => true,
            ],
            is_public: 1,
            tags: [$to,'first-message'],
        );

        $leadSystemModule = SystemModulesRepository::getByModelName(get_class($lead), $lead->app);
        $newMessage = new CreateMessageAction(
            $messageInput,
            $leadSystemModule,
            $lead->getId()
        );
        $newMessage->runWorkflow = $runWorkflow;

        return $newMessage->execute();
    }

    private function addMessageToChannel(Message $message, ?Channel $channel, Lead $lead): void
    {
        if ($channel === null) {
            return;
        }

        $channel->addCategory(
            'ai-agent',
            $lead->app,
            $lead->user,
            $lead->company
        );
        $channel->addMessage($message);
    }
}
