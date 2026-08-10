<?php

declare(strict_types=1);

namespace Kanvas\Guild\Actions;

use Kanvas\Guild\Models\BaseModel;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction as CreateSocialMessageAction;
use Kanvas\Social\Messages\DataTransferObject\AiChatMessagePayload;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;
use Throwable;

abstract class RecordEntityNoteAction
{
    abstract protected function entity(): BaseModel;

    abstract protected function resolveNotesChannel(Users $user): ?Channel;

    /**
     * @param  Users|null  $actingUser  Attribution. Null → AI agent user (agent-authored); pass a human for manager records.
     * @param  bool  $fromIa  AI-authored. Pair null actingUser + true for AI, or a human + false for manager records.
     */
    public function execute(
        string $body,
        string $tag = 'note',
        ?Users $actingUser = null,
        bool $fromIa = true
    ): ?Message {
        try {
            $entity = $this->entity();

            $user = $actingUser ?? $entity->company->getAiAgentUser() ?? $entity->user;
            if ($user === null) {
                return null;
            }

            $channel = $this->resolveNotesChannel($user);
            if ($channel === null) {
                return null;
            }

            $messageType = MessageTypeService::getOrCreate($entity->app, 'system');

            $messagePayload = new AiChatMessagePayload(
                content: $body,
                from_me: true,
                from_ia: $fromIa,
                raw_data: $body,
            );

            $messageInput = new MessageInput(
                app: $entity->app,
                company: $entity->company,
                user: $user,
                type: $messageType,
                message: $messagePayload->toArray(),
                is_public: 0,
            );

            $note = new CreateSocialMessageAction(
                $messageInput,
                SystemModulesRepository::getByModelName($entity::class, $entity->app),
                $entity->getId(),
            )->execute();

            $channel->addMessage($note);
            $note->addTag($tag);

            return $note;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
