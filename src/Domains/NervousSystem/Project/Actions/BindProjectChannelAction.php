<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Actions;

use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelData;
use Kanvas\Social\Channels\Models\Channel;
use Throwable;

/**
 * Provision the project's default Social channel — the primary feed the PM and members read/post to
 * (transcripts, notes, chat). A project can have many channels (polymorphic on entity_id, like a
 * Lead's notes); this is the one flagged on `default_channel_id`.
 *
 * Best-effort: a failure to create the channel must not block project creation — the project still
 * works and a channel can be created on first message.
 */
class BindProjectChannelAction
{
    public function __construct(
        private readonly Project $project,
    ) {
    }

    public function execute(): ?Channel
    {
        $owner = $this->project->user;
        if ($owner === null) {
            return null;
        }

        try {
            $channel = new CreateChannelAction(
                new ChannelData(
                    apps: $this->project->app,
                    companies: $this->project->company,
                    users: $owner,
                    entity_id: (string) $this->project->getKey(),
                    entity_namespace: Project::class,
                    name: $this->project->title,
                    description: $this->project->objective ?? $this->project->title,
                    slug: $this->project->uuid,
                ),
            )->execute();

            $this->project->default_channel_id = $channel->getId();
            $this->project->saveQuietly();

            return $channel;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
