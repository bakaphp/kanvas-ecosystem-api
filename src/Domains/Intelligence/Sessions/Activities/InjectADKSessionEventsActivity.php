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
                $sessions = Session::where('entity_namespace', get_class($entity))
                    ->where('entity_id', $entity->getId())
                    ->where('is_deleted', 0)
                    ->fromApp($app)
                    ->get();

                if ($sessions->isEmpty()) {
                    return [
                        'message' => 'No active sessions found for entity',
                        'entity' => $entity,
                    ];
                }

                $injected = 0;

                foreach ($sessions as $session) {
                    if (! $session->agent || ! $session->agent->type) {
                        continue;
                    }

                    $handler = new $session->agent->type->handler();

                    if (! $handler instanceof ADKAgent) {
                        continue;
                    }

                    $adkService = new GoogleADKService(
                        $channel->app,
                        $channel->company
                    );

                    $userId = (string) $session->entity_id;

                    $adkService->injectSessionEvents(
                        $userId,
                        $session->uuid,
                        [
                            [
                                'role' => 'salesperson',
                                'text' => $content,
                            ],
                        ]
                    );

                    $injected++;
                }

                return [
                    'message' => "Injected events into {$injected} ADK session(s)",
                    'entity' => $entity,
                    'sessions_injected' => $injected,
                ];
            },
            company: $channel->company,
        );
    }
}
