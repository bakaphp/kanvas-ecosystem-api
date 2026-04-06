<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Sessions\Activities;

use GuzzleHttp\Exception\ClientException;
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
                $eligible = 0;
                $errors = [];

                foreach ($sessions as $session) {
                    if (! $session->agent || ! $session->agent->type) {
                        continue;
                    }

                    $handler = new $session->agent->type->handler();

                    if (! $handler instanceof ADKAgent) {
                        continue;
                    }

                    $eligible++;

                    $adkService = new GoogleADKService(
                        $channel->app,
                        $channel->company
                    );

                    try {
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

                        $injected++;
                    } catch (ClientException $e) {
                        $errors[] = "Failed to inject events into ADK session {$session->uuid}: " . $e->getMessage();
                    }
                }

                if ($eligible === 0) {
                    return [
                        'message' => 'No eligible ADK sessions found for entity',
                        'entity' => $entity,
                        'sessions_injected' => 0,
                        'errors' => [],
                    ];
                }

                if ($injected === 0) {
                    return $this->failWorkflow([
                        'message' => 'Failed to inject events into any ADK sessions',
                        'entity' => $entity,
                        'sessions_injected' => 0,
                        'errors' => $errors,
                    ]);
                }

                $message = $injected === $eligible
                    ? "Successfully injected events into all {$injected} ADK session(s)"
                    : "Injected events into {$injected} of {$eligible} ADK session(s) with some errors";

                return [
                    'message' => $message,
                    'entity' => $entity,
                    'sessions_injected' => $injected,
                    'errors' => $errors,
                ];
            },
            company: $channel->company,
        );
    }
}
