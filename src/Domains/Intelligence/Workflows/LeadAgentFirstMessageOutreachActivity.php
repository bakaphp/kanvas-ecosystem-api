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
                $createContext = new CreateLeadContextInfoAction($lead)->execute($params);

                //get the first message
                $firstLeadMessage = new CreateLeadFirstEngagementMessageAction($lead)->execute();

                //set the first message
                $leadContext = $lead->get(EnumsConfigurationEnum::LEAD_CONTEXT_INFO->value);
                $leadContext['first_message'] = $firstLeadMessage;
                $lead->set(EnumsConfigurationEnum::LEAD_CONTEXT_INFO->value, $leadContext);
                $lead->set(LeadsEnumsConfigurationEnum::FIRST_MESSAGE->value, $firstLeadMessage['message']);
                $cellPhone = $lead->people->getCellPhones()->first()?->value ?? $lead->people->getPhones()->first()?->value ?? '';
                $email = $lead->people->getEmails()->first()?->value ?? '';

                $cellPhone = preg_replace('/^\+?1/', '', $cellPhone);
                $communicationChannel = $lead->get(LeadsEnumsConfigurationEnum::AGENT_COMMUNICATION_CHANNEL->value);

                //$lead->set(LeadsEnumsConfigurationEnum::AGENT_COMMUNICATION_CHANNEL->value, 'sms');
                if (empty($communicationChannel)) {
                    return [
                        'error' => 'No communication channel selected , please set one to be able to send messages',
                        'context' => $createContext,
                        'first_message' => $firstLeadMessage,
                    ];
                }

                $communicationChannelNumber = match ($communicationChannel) {
                    'sms' => $cellPhone,
                    'email' => $email,
                    default => $cellPhone
                };

                if (empty($communicationChannelNumber)) {
                    throw new RuntimeException('Lead does not have a phone number or email, wont be able to send message until we add email support');
                }

                if (isset($params['create_session'])) {
                    $channel = ChannelDto::from([
                        'apps' => $app,
                        'companies' => $lead->company,
                        'users' => $lead->user,
                        'entity_id' => $lead->getId(),
                        'entity_namespace' => Lead::class,
                        'name' => 'Lead ' . $lead->getId() . ' Session',
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
                if ($lead->company->get('allow_session_hijack', false)
                    && $lead->company->get('overwrite_phone_number') !== null) {
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
                    $leadCurrentDateIn = null;
                    if ($lead->get('downloaded_from_eleads')) {
                        $eLeadOpportunity = EntitiesLead::getById($lead->app, $lead->company, (string) $lead->get(CustomFieldEnum::OPPORTUNITY_ID->value));
                        $leadCurrentDateIn = (string) $eLeadOpportunity->dateIn;
                    } else {
                    }

                    $messageType = match ($communicationChannel) {
                        'sms' => 'twilio-sms',
                        'email' => 'mailgun-email',
                        default => 'twilio-sms',
                    };

                    //$doubleCheckIsInternet = Str::contains((string) $eLeadOpportunity->upType, 'Internet', true); //@this is not needed but just in case

                    if ($leadCurrentDateIn && $this->isWithinOneDay($lead, $leadCurrentDateIn)) {
                        new SendMessageToLeadAction($lead)->execute(
                            $communicationChannel,
                            $firstLeadMessage['message'],
                            $params['from'] ?? null,
                            $firstLeadMessage['title'] ?? null,
                        );
                        $lead->set(LeadsEnumsConfigurationEnum::SENT_FIRST_MESSAGE_AT->value, date('Y-m-d H:i:s'));

                        //$createMessage = $this->createMessage($lead, $firstLeadMessage['message'], $cellPhone, $channel ?? null);
                        $createMessage = $this->createMessage(
                            $lead,
                            $firstLeadMessage['message'],
                            $communicationChannelNumber,
                            $channel ?? null,
                            $messageType
                        );

                        try {
                            //todo this is not the right place to do this but for now its ok
                            //we need to make sure we have the phone call activity
                            if ($lead->get('downloaded_from_eleads')) {
                                $outBoundPhoneCallActivity = new AddOutBoundPhoneCallActivityToLeadAction($lead)
                                ->execute('Sally Take Over', 'Sally stop the clock');
                            } elseif ($lead->get('downloaded_from_vin_solution')) {
                                // To do VinSolution Push Note to Lead
                            }
                        } catch (Exception $e) {
                            report($e);
                        }
                    }
                }

                $timezone = $lead->company->get('timezone') ?? 'UTC';
                $now = Carbon::now($timezone);

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
                    //'double_check_is_internet' => $doubleCheckIsInternet ?? null,
                ];
            }
        );
    }

    public function isWithinOneDay(Lead $lead, string $dateString): bool
    {
        $leadTimezone = $lead->company->get('timezone', 'America/New_York') ?? 'America/New_York';

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
        )->execute();
        //$newMessage = $createMessageAction->execute();
        //$newMessage->addEntity($lead);
        if ($channel) {
            $channel->addMessage($newMessage);
        }

        return $newMessage;
    }
}
