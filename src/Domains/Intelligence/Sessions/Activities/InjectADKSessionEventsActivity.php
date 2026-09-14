<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Sessions\Activities;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Services\GoogleADKService;
use Kanvas\Intelligence\Agents\Types\ADKAgent;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Channels\Enums\ChannelNameEnum;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Throwable;

#[WorkflowAction(
    name: 'Inject ADK Session Events',
    description: 'Feeds channel activity into an ADK agent\'s session so its remote conversation stays in step '
        . 'with what happened in Kanvas. Plumbing for ADK-backed agents; not something to wire by hand.',
)]
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
        $fromHumanAgent = (bool) ($messageData['from_human'] ?? false);
        $fromMe = (bool) ($messageData['from_me'] ?? false);

        if (empty($content) || ! $fromHumanAgent) {
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
            integrationOperation: function () use ($app, $channel, $entity, $content, $fromMe) {
                $adkService = new GoogleADKService(
                    $channel->app,
                    $channel->company
                );

                if ($channel->isNoteChannel()) {
                    return $this->injectNoteChannelEvents(
                        $adkService,
                        $channel,
                        $app,
                        $entity,
                        $content
                    );
                }

                return $this->injectSessionChannelEvents(
                    $adkService,
                    $app,
                    $channel,
                    $entity,
                    $content,
                    $fromMe
                );
            },
            company: $channel->company,
        );
    }

    private function injectNoteChannelEvents(
        GoogleADKService $adkService,
        Channel $channel,
        Apps $app,
        Model $entity,
        string $content
    ): array {
        $communicationChannel = Channel::where('entity_id', $channel->entity_id)
            ->where('entity_namespace', $channel->entity_namespace)
            ->where('name', '!=', ChannelNameEnum::NOTES->value)
            ->where('is_deleted', 0)
            ->fromApp($app)
            ->latest('updated_at')
            ->first();

        if (! $communicationChannel) {
            return [
                'message' => 'No communication channel found for notes channel entity',
                'entity' => $entity,
            ];
        }

        $session = Session::where('channel_id', $communicationChannel->getId())
            ->where('is_deleted', 0)
            ->fromApp($app)
            ->latest()
            ->first();

        if (! $session) {
            return [
                'message' => 'No active session found for communication channel',
                'entity' => $entity,
            ];
        }

        $this->ensureAdkSessionStarted(
            $adkService,
            $entity,
            (string) $session->entity_id,
            $session->uuid,
            'adk_session_started_' . (string) $session->getId()
        );

        $adkService->injectSessionEvents(
            (string) $session->entity_id,
            $session->uuid,
            [
                [
                    'role' => 'notes',
                    'text' => $content,
                ],
            ]
        );

        return [
            'message' => "Successfully injected notes into ADK session {$session->uuid}",
            'entity' => $entity,
            'session_uuid' => $session->uuid,
        ];
    }

    private function injectSessionChannelEvents(
        GoogleADKService $adkService,
        Apps $app,
        Channel $channel,
        Model $entity,
        string $content,
        bool $fromMe
    ): array {
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

        $this->ensureAdkSessionStarted(
            $adkService,
            $entity,
            (string) $session->entity_id,
            $session->uuid,
            'adk_session_started_' . (string) $session->getId()
        );

        $role = $fromMe ? 'salesperson' : 'user';

        $adkService->injectSessionEvents(
            (string) $session->entity_id,
            $session->uuid,
            [
                [
                    'role' => $role,
                    'text' => $content,
                ],
            ]
        );

        return [
            'message' => "Successfully injected events into ADK session {$session->uuid}",
            'entity' => $entity,
            'session_uuid' => $session->uuid,
        ];
    }

    private function ensureAdkSessionStarted(
        GoogleADKService $adkService,
        Model $entity,
        string $entityId,
        string $sessionUuid,
        string $customFieldKey
    ): void {
        if ($entity->get($customFieldKey)) {
            return;
        }

        try {
            $adkService->startSession($entityId, $sessionUuid);
            $entity->set($customFieldKey, true);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
