<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Workflows;

use Baka\Support\Str;
use Exception;
use Illuminate\Support\Carbon;
use Kanvas\ActionEngine\Pipelines\Models\Pipeline;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Elead\Actions\AddOutBoundPhoneCallActivityToLeadAction;
use Kanvas\Connectors\Elead\Entities\Lead as EntitiesLead;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadsEnumsConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\ConfigurationEnum as EnumsConfigurationEnum;
use Kanvas\Intelligence\Leads\Actions\CreateLeadContextInfoAction;
use Kanvas\Intelligence\Leads\Actions\CreateLeadFirstEngagementMessageAction;
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
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use RuntimeException;

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
                try {
                    $createContext = new CreateLeadContextInfoAction($lead)->execute($params);
                } catch (Exception $e) {
                    return $this->failWorkflow([
                        'error' => 'Error creating lead context: ' . $e->getMessage(),
                    ]);
                }

                $cellPhone = $lead->people->getCellPhones()->first()?->value ?? ''; //$lead->people->getPhones()->first()?->value ?? '';
                $email = $lead->people->getEmails()->first()?->value ?? '';
                $cellPhone = preg_replace('/^\+?1/', '', $cellPhone);
                $source = $lead->source?->name ?? '';

                //for now avoid service
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

                $channels = [
                    'sms' => $cellPhone,
                    'email' => $email,
                    //'whatsapp' => $cellPhone,
                ];

                $stageConfig = $lead->getCurrentPipelineStage()->config['notification_engagement_rules'];
                $totalSentMessages = 0;
                $sentChannels = [];
                $stopTheClock = false;

                foreach ($channels as $communicationChannel => $value) {
                    //get the first message
                    if ($value === null || empty($value)) {
                        continue;
                    }
                    $template = $stageConfig['templates'][$communicationChannel] ?? null;

                    if ($template === null || empty($template)) {
                        continue;
                    }
                    $firstLeadMessage = new CreateLeadFirstEngagementMessageAction($lead, $template)->execute();

                    //set the first message
                    $leadContext = $lead->get(EnumsConfigurationEnum::LEAD_CONTEXT_INFO->value);
                    $leadContext['first_message'] = $firstLeadMessage;
                    $lead->set(EnumsConfigurationEnum::LEAD_CONTEXT_INFO->value, $leadContext);
                    $lead->set(LeadsEnumsConfigurationEnum::FIRST_MESSAGE->value, $firstLeadMessage['message']);
                    // $communicationChannel = $lead->get(LeadsEnumsConfigurationEnum::AGENT_COMMUNICATION_CHANNEL->value);

                    //$lead->set(LeadsEnumsConfigurationEnum::AGENT_COMMUNICATION_CHANNEL->value, 'sms');
                    // if (empty($communicationChannel)) {
                    //     return $this->failWorkflow([
                    //         'error' => 'No communication channel selected , please set one to be able to send messages',
                    //         'context' => $createContext,
                    //         'first_message' => $firstLeadMessage,
                    //     ]);
                    // }

                    $communicationChannelNumber = match ($communicationChannel) {
                        'sms' => $cellPhone,
                        'email' => $email,
                        'whatsapp' => $cellPhone,
                        default => $cellPhone
                    };

                    if (empty($communicationChannelNumber)) {
                        //throw new RuntimeException('Lead does not have a phone number or email, wont be able to send message until we add email support');
                        return $this->failWorkflow([
                            'error' => 'Lead does not have a phone number or email for channel ' . $communicationChannel . ', wont be able to send message until we add email support',
                            //'context' => $createContext,
                            //'first_message' => $firstLeadMessage,
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
                        $channel = (new CreateChannelAction($channel))->execute();

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
                    if (! isset($params['disable_sending'])) {
                        $leadCurrentDateIn = $this->getLeadCreatedAt($lead);

                        $messageType = match ($communicationChannel) {
                            'sms' => 'twilio-sms',
                            'email' => 'mailgun-email',
                            'whatsapp' => 'whatsapp',
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
                                    $channel ?? null,
                                    $messageType,
                                    $shouldSendFirstMessageNow
                                );

                                if ($shouldSendFirstMessageNow) {
                                    new SendMessageToLeadAction($lead)->execute(
                                        $communicationChannel,
                                        $firstLeadMessage['message'],
                                        $params['from'] ?? null,
                                        $firstLeadMessage['title'] ?? null,
                                    );

                                    $stopTheClock = true;
                                    $lead->set(LeadsEnumsConfigurationEnum::SENT_FIRST_MESSAGE_AT->value, date('Y-m-d H:i:s'));
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

                                    DailyReportService::track(
                                        $app,
                                        $lead->company,
                                        'ai_delayed_message_scheduled'
                                    );
                                }

                                //only do the external activity once for the first message
                                if ($totalSentMessages === 0 && $stopTheClock) {
                                    $outBoundPhoneCallActivity = $this->leadExternalActivityDateIn($lead, $createMessage);
                                }
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
        $company = $lead->company;

        // If company does NOT enforce the rule "send only during off-hours",
        // we can always send.
        if (! $company->get(EnumsConfigurationEnum::FIRST_MESSAGE_ONLY_DURING_OFF_BUSINESS_HOURS->value, false)) {
            return true;
        }

        // Rule *is enabled*: allow only outside business hours.
        return ! $company->isWithinWorkingHours(now());
    }

    private function getLeadCreatedAt(Lead $lead): ?string
    {
        $leadCurrentDateIn = null;
        if ($lead->get('downloaded_from_eleads')) {
            $eLeadOpportunity = EntitiesLead::getById($lead->app, $lead->company, (string) $lead->get(CustomFieldEnum::OPPORTUNITY_ID->value));
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
            ->execute('Sally Take Over', 'Sally stop the clock');
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
        ?Channel $channel = null,
        string $messageType = 'twilio-sms',
        bool $runWorkflow = true,
    ): Message {
        $user = $lead->user;
        $agentUser = $lead->app->get('kanvas_agent_user_id');
        if ($agentUser !== null) {
            $user = Users::getById((int) $agentUser);
        }

        $messageTypeModel = (new CreateMessageTypeAction(
            new MessageTypeInput(
                $lead->app->getId(),
                0,
                $messageType,
                $messageType,
            )
        ))->execute();

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
            tags: [$to],
            //slug: Str::slug($text) . '-' . microtime()
        );

        $leadSystemModule = SystemModulesRepository::getByModelName(get_class($lead), $lead->app);
        $newMessage = new CreateMessageAction(
            $messageInput,
            $leadSystemModule,
            $lead->getId()
        );
        $newMessage->runWorkflow = $runWorkflow;

        $newMessage = $newMessage->execute();
        //$newMessage = $createMessageAction->execute();
        //$newMessage->addEntity($lead);
        if ($channel) {
            $channel->addCategory(
                'ai-agent',
                $lead->app,
                $lead->user,
                $lead->company
            );
            $channel->addMessage($newMessage);
        }

        return $newMessage;
    }
}
