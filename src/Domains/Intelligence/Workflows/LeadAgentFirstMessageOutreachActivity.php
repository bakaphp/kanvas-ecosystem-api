<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Workflows;

use DateTime;
use DateTimeZone;
use Exception;
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

                $cellPhone = preg_replace('/^\+?1/', '', $cellPhone);
                if (empty($cellPhone)) {
                    throw new RuntimeException('Lead does not have a phone number , wont be able to send message until we add email support');
                }

                //$lead->set(LeadsEnumsConfigurationEnum::AGENT_COMMUNICATION_CHANNEL->value, 'sms');
                if (empty($lead->get(LeadsEnumsConfigurationEnum::AGENT_COMMUNICATION_CHANNEL->value))) {
                    return [
                        'error' => 'No communication channel selected , please set one to be able to send messages',
                        'context' => $createContext,
                        'first_message' => $firstLeadMessage,
                    ];
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
                            $lead->get(LeadsEnumsConfigurationEnum::AGENT_COMMUNICATION_CHANNEL->value),
                            $cellPhone
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
                            $lead->get(LeadsEnumsConfigurationEnum::AGENT_COMMUNICATION_CHANNEL->value),
                            $cellPhone
                        ),
                    ]);
                    new CreateSessionAction($sessionDto)->execute();
                }

                //hijack session
                if ($lead->company->get('allow_session_hijack', false)
                    && $lead->company->get('overwrite_phone_number') !== null) {
                    $overwriteConfig = $lead->company->get('overwrite_phone_number');
                    $overwriteConfig = array_flip($overwriteConfig);
                    $originalRemoteJid = match ($lead->get(LeadsEnumsConfigurationEnum::AGENT_COMMUNICATION_CHANNEL->value)) {
                        'whatsapp' => $cellPhone = $cellPhone . '@s.whatsapp.net',
                        'sms' => '+' . $cellPhone
                    };

                    if (isset($overwriteConfig[$originalRemoteJid])) {
                        unset($params['disable_sending']);
                    }
                }

                //send the first message
                if (! isset($params['disable_sending'])) {
                    $eLeadOpportunity = EntitiesLead::getById($lead->app, $lead->company, (string) $lead->get(CustomFieldEnum::OPPORTUNITY_ID->value));
                    $leadCurrentDateIn = $eLeadOpportunity->currentDateIn();

                    if ($leadCurrentDateIn && $this->isToday($leadCurrentDateIn)) {
                        new SendMessageToLeadAction($lead)->execute(
                            $lead->get(LeadsEnumsConfigurationEnum::AGENT_COMMUNICATION_CHANNEL->value),
                            $firstLeadMessage['message'],
                            $params['from'] ?? null,
                        );
                        $lead->set(LeadsEnumsConfigurationEnum::SENT_FIRST_MESSAGE_AT->value, date('Y-m-d H:i:s'));

                        try {
                            //todo this is not the right place to do this but for now its ok
                            //we need to make sure we have the phone call activity
                            $outBoundPhoneCallActivity = new AddOutBoundPhoneCallActivityToLeadAction($lead)->execute();

                            $note = $firstLeadMessage['message'] ?? '';
                            if (! empty($note)) {
                                $fromAgent = true;
                                $note = ($fromAgent ? 'Sally: ' : 'Customer: ') . $note;
                                $eLeadOpportunity->addComment($note);
                            }
                        } catch (Exception $e) {
                            report($e);
                        }
                    }
                }

                //move to stage 2 of the pipeline
                $lead->moveToNextPipelineStage();

                return [
                    'context' => $createContext,
                    'first_message' => $firstLeadMessage,
                    'outbound_call_activity' => $outBoundPhoneCallActivity ?? null,
                ];
            }
        );
    }

    public function isToday(Lead $lead, string $dateString): bool
    {
        // Get today's date in NY timezone
        $todayNY = (new DateTime('now', new DateTimeZone($lead->company->get('timezone', 'America/New_York') ?? 'America/New_York')))->format('Y-m-d');

        // Extract just the date part (YYYY-MM-DD) from the dateString
        $dateOnly = substr($dateString, 0, 10);

        return $todayNY === $dateOnly;
    }
}
