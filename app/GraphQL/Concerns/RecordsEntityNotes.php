<?php

declare(strict_types=1);

namespace App\GraphQL\Concerns;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Actions\CreateEntityNotesChannelAction;
use Kanvas\Guild\Models\BaseModel;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;

/**
 * Notes-channel plumbing for the Guild entities that expose a `notes` thread. Kept in one place
 * because the per-entity copies had already drifted into sharing a bug — both posted through
 * MessageInput::channel_slug, which silently orphans the note when the slug misses.
 *
 * The using class supplies only the entity lookup, which is genuinely per-domain.
 */
trait RecordsEntityNotes
{
    /**
     * Wording for the ownership errors: "person", "organization".
     */
    abstract protected function noteEntityLabel(): string;

    private function postNoteToEntityChannel(BaseModel $entity, array $input, UserInterface $user): Message
    {
        $app = $entity->app;

        $channel = isset($input['channel_id'])
            ? $this->resolveEntityChannel($entity, (int) $input['channel_id'])
            : $this->resolveNotesChannel($entity);

        $message = new CreateMessageAction(
            new MessageInput(
                app: $app,
                company: $entity->company,
                user: $user,
                type: MessageTypeService::getOrCreate($app, $input['message_verb'] ?? 'comment'),
                message: $input['message'],
            ),
        )->execute();

        // Attaching by hand rather than through MessageInput::channel_slug: on a slug miss
        // CreateMessageAction creates a throwaway channel bound to the Message itself, which
        // would orphan the note off its entity. Same reason PostChannelMessageAction exists.
        $channel->addMessage($message, $user);

        return $message;
    }

    /**
     * The observer creates it on insert, so this only self-heals rows that predate that.
     */
    private function resolveNotesChannel(BaseModel $entity): Channel
    {
        $channel = $entity->notes ?? new CreateEntityNotesChannelAction($entity)->execute();

        if ($channel === null) {
            throw new ValidationException(
                'This ' . $this->noteEntityLabel() . ' has no notes channel and none could be created'
            );
        }

        return $channel;
    }

    private function resolveEntityChannel(BaseModel $entity, int $channelId): Channel
    {
        /** @var Channel $channel */
        $channel = Channel::getByIdFromCompanyApp($channelId, $entity->company, $entity->app);

        $belongsToEntity = (int) $channel->entity_id === $entity->getId()
            && $channel->entity_namespace === $entity::class;

        if (! $belongsToEntity) {
            throw new ValidationException('The channel does not belong to this ' . $this->noteEntityLabel());
        }

        return $channel;
    }
}
