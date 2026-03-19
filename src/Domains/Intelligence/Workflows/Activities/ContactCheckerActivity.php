<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Guild\Leads\Enums\LeadGroupStatusEnum;
use Kanvas\Intelligence\Tools\ContactCheckerTool;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

class ContactCheckerActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        if (! $entity instanceof Message) {
            return [
                'status' => 'skipped',
                'message' => 'Entity is not a Message',
            ];
        }

        $channel = $entity->channels()
            ->where('name', 'Notes')
            ->first();

        if (! $channel) {
            return [
                'status' => 'skipped',
                'message' => 'Message is not associated with a Notes channel',
            ];
        }

        $lead = $entity->entity();

        if (! $lead) {
            return [
                'status' => 'skipped',
                'message' => 'Could not find associated Lead',
            ];
        }

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function ($message, $app) use ($lead) {
                $tool = new ContactCheckerTool($message);
                $result = $tool->execute();

                if ($result['already_contacted'] === true) {
                    $lead->setContactStatus(LeadGroupStatusEnum::CONTACTED);
                }

                return [
                    'status' => 'success',
                    'lead_id' => $lead->getId(),
                    'already_contacted' => $result['already_contacted'],
                    'should_send_first_message' => $result['should_send_first_message'],
                    'note_is_relevant' => $result['note_is_relevant'],
                    'confidence' => $result['confidence'],
                    'reason' => $result['reason'],
                    '_thought_process' => $result['_thought_process'],
                ];
            },
            company: $lead->company,
        );
    }
}
