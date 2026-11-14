<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Activities;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Elead\Actions\AddOutBoundPhoneCallActivityToLeadAction;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

/**
 * Elead times how long a dealer takes to answer a new opportunity. The agent's first message is
 * that answer, so it is logged on the opportunity as a completed phone call, which is what stops
 * the clock.
 */
#[WorkflowAction(
    name: 'Elead Stop Response Clock',
    description: 'Logs the agent\'s first message to a lead on its Elead opportunity as a completed phone call, which stops Elead\'s response-time clock (runs on agent-reached-out, param message_id)',
    integration: IntegrationsEnum::ELEAD,
)]
class StopResponseClockActivity extends KanvasActivity
{
    public function execute(Model $entity, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        $messageId = (int) ($params['message_id'] ?? 0);

        if (! $entity instanceof Lead || $messageId === 0 || ! $entity->get('downloaded_from_eleads')) {
            return ['message' => 'Not a lead Elead is timing; nothing to stop', 'entity' => null];
        }

        if (empty($entity->get(CustomFieldEnum::OPPORTUNITY_ID->value))) {
            return $this->failWorkflow([
                'message' => 'Lead has no Elead opportunity to log the answer on',
                'lead_id' => $entity->getId(),
            ]);
        }

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::ELEAD,
            additionalParams: $params,
            integrationOperation: fn (): array => [
                'message' => 'Elead response clock stopped',
                'lead_id' => $entity->getId(),
                'activity_id' => new AddOutBoundPhoneCallActivityToLeadAction(
                    $entity,
                    Message::getById($messageId, $app)
                )->execute('Sally Takes Over', 'Sally stops the clock'),
            ],
            company: $entity->company,
        );
    }
}
