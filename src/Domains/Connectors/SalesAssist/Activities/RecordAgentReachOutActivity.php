<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\SalesAssist\Actions\RecordAgentReachOutAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction(
    name: 'Sales Assist Record Agent Reach Out',
    description: 'Shows the agent\'s first message to a lead on the lead\'s legacy per-protocol SalesAssist channels '
        . '(runs on agent-reached-out, params agent_id, message_ids_sent, message_ids_scheduled)',
)]
class RecordAgentReachOutActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Model $entity, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        $messageIds = [...($params['message_ids_sent'] ?? []), ...($params['message_ids_scheduled'] ?? [])];

        if (! $entity instanceof Lead || $messageIds === []) {
            return $this->skipWorkflow('No reach-out message to record');
        }

        new RecordAgentReachOutAction(
            $entity,
            [
                'agent_id' => (int) ($params['agent_id'] ?? 0),
                'message_ids_sent' => $params['message_ids_sent'] ?? [],
                'message_ids_scheduled' => $params['message_ids_scheduled'] ?? [],
            ]
        )->execute();

        return ['message' => 'Reach-out recorded on the lead\'s channels', 'message_ids' => $messageIds];
    }
}
