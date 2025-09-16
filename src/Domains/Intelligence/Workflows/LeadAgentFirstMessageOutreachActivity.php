<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Workflows;

use Kanvas\ActionEngine\Pipelines\Models\Pipeline;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadsEnumsConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\ConfigurationEnum as EnumsConfigurationEnum;
use Kanvas\Intelligence\Leads\Actions\CreateLeadContextInfoAction;
use Kanvas\Intelligence\Leads\Actions\CreateLeadFirstEngagementMessageAction;
use Kanvas\Intelligence\Sessions\Actions\CreateSessionAction;
use Kanvas\Intelligence\Sessions\Actions\CreateSessionUUidAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

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
            integrationOperation: function ($lead, $app, $integrationCompany, $additionalParams) use ($params) {
                $createContext = new CreateLeadContextInfoAction($lead)->execute($params);

                //get the first message
                $firstLeadMessage = new CreateLeadFirstEngagementMessageAction($lead)->execute();

                //set the first message
                $leadContext = $lead->get(EnumsConfigurationEnum::LEAD_CONTEXT_INFO->value);
                $leadContext['first_message'] = $firstLeadMessage;
                $lead->set(EnumsConfigurationEnum::LEAD_CONTEXT_INFO->value, $leadContext);
                $lead->set(LeadsEnumsConfigurationEnum::FIRST_MESSAGE->value, $firstLeadMessage['message']);

                //send the first message
                if (! isset($params['disable_sending'])) {
                    new SendMessageToLeadAction($lead)->execute(
                        $lead->get(LeadsEnumsConfigurationEnum::AGENT_COMMUNICATION_CHANNEL->value),
                        $firstLeadMessage['message']
                    );
                }
                if (isset($params['create_session'])) {
                    $channel = ChannelDto::from([
                        'apps' => $app,
                        'companies' => $lead->company,
                        'users' => $lead->user,
                        'entity_id' => $lead->getId(),
                        'entity_namespace' => Lead::class,
                        'name' => 'Lead ' . $lead->getId() . ' Session',
                        'slug' => new CreateSessionUUidAction()->execute(
                            $lead->get(LeadsEnumsConfigurationEnum::AGENT_COMMUNICATION_CHANNEL->value),
                            $lead->people->phones->first()->value
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
                        'canal_id' => $channel->slug,
                    ]);
                    new CreateSessionAction($sessionDto)->execute();
                }

                //move to stage 2 of the pipeline
                $lead->moveToNextPipelineStage();

                return [
                    'context' => $createContext,
                    'first_message' => $firstLeadMessage,
                ];
            }
        );
    }
}
