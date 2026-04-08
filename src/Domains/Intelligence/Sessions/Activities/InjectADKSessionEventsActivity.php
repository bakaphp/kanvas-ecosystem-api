<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Sessions\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Services\GoogleADKService;
use Kanvas\Intelligence\Agents\Types\ADKAgent;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Throwable;

class InjectADKSessionEventsActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Channel $channel, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);
        $message = $params['message'] ?? null;

        if (! $message instanceof Message) {
            return $this->failWorkflow([
                'message' => 'Message not found',
                'entity' => null,
            ]);
        }

        $messageData = $message->message;
        $content = $messageData['content'] ?? '';
        $fromHumanAgent = $messageData['from_human'] ?? false;

        if (empty($content) || empty($fromHumanAgent)) {
            return $this->failWorkflow([
                'message' => 'Message has no content or is not from a human agent',
                'entity' => null,
            ]);
        }

        $entity = $message->entity() ?? $channel->entityData();

        if (! $entity) {
            return $this->failWorkflow([
                'message' => 'No entity found for message or channel',
                'entity' => null,
            ]);
        }

        return $this->executeIntegration(
            entity: $channel,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function () use ($app, $channel, $entity, $content) {
                $session = Session::where('channel_id', $channel->getId())
                    ->where('is_deleted', 0)
                    ->fromApp($app)
                    ->first();

                if (! $session) {
                    return [
                        'message' => 'No active session found for channel',
                        'entity' => $entity,
                    ];
                }

                if (! $session->agent || ! $session->agent->type) {
                    return [
                        'message' => 'Session has no agent or agent type configured',
                        'entity' => $entity,
                    ];
                }

                $handler = new $session->agent->type->handler();

                if (! $handler instanceof ADKAgent) {
                    return [
                        'message' => 'Session agent is not an ADK agent',
                        'entity' => $entity,
                    ];
                }

                $adkService = new GoogleADKService(
                    $channel->app,
                    $channel->company
                );

                if (! $entity->get('adk_session_started_' . (string) $session->getId())) {
                    try {
                        $adkService->startSession(
                            (string) $session->entity_id,
                            $session->uuid
                        );
                        $entity->set('adk_session_started_' . (string) $session->getId(), true);
                    } catch (Throwable $e) {
                        report($e);
                    }
                }

                $adkService->injectSessionEvents(
                    (string) $session->entity_id,
                    $session->uuid,
                    [
                        [
                            'role' => 'salesperson',
                            'text' => $content,
                        ],
                    ]
                );

                return [
                    'message' => "Successfully injected events into ADK session {$session->uuid}",
                    'entity' => $entity,
                    'session_uuid' => $session->uuid,
                ];
            },
            company: $channel->company,
        );
    }
}
