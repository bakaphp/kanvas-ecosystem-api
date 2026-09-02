<?php

declare(strict_types=1);

namespace Kanvas\Guild\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Str;
use Kanvas\Guild\Models\BaseModel;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Enums\ChannelNameEnum;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Users\Models\Users;

/**
 * The slug is derived from the entity class ("people-notes-12"), matching the deal-notes-{id}
 * channels already in the wild. It has to stay stable per entity type: the UI resolves the note
 * thread by slug, and CreateChannelAction dedupes on it.
 */
class CreateEntityNotesChannelAction
{
    public function __construct(
        private readonly BaseModel $entity,
    ) {
    }

    /**
     * $user is the person whose action is creating the channel. It matters: CreateChannelAction writes
     * whoever it is given into channels.users_id AND into channel_users as admin, so defaulting to the
     * entity's creator silently makes the record's owner the admin of a thread someone else started.
     */
    public function execute(?UserInterface $user = null): ?Channel
    {
        $owner = $user ?? $this->entity->user ?? $this->entity->company?->user;

        if (! $owner instanceof Users) {
            return null;
        }

        return new CreateChannelAction(
            new ChannelDto(
                apps: $this->entity->app,
                companies: $this->entity->company,
                users: $owner,
                entity_id: $this->entity->getId(),
                entity_namespace: $this->entity::class,
                name: ChannelNameEnum::NOTES->value,
                description: class_basename($this->entity) . ' notes channel.',
                slug: $this->slug(),
            )
        )->execute();
    }

    public function slug(): string
    {
        return Str::slug(class_basename($this->entity)) . '-notes-' . (string) $this->entity->getId();
    }
}
