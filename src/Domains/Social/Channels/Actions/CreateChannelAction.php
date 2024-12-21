<?php

declare(strict_types=1);

namespace Kanvas\Social\Channels\Actions;

use Baka\Support\Str;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Models\Channel;

class CreateChannelAction
{
    public function __construct(
        protected ChannelDto $channelDto
    ) {
    }

    public function execute(): Channel
    {
        $channel = Channel::firstOrCreate([
            'apps_id' => $this->channelDto->apps->id,
            'companies_id' => $this->channelDto->companies->id,
            'name' => $this->channelDto->name,
            'slug' => $this->channelDto->slug ?? Str::slug($this->channelDto->name),
            'description' => $this->channelDto->description,
            'entity_id' => $this->channelDto->entity_id,
            'entity_namespace' => $this->channelDto->entity_namespace,
        ]);

        $channel->users()->attach(
            $this->channelDto->users->id,
            [
                'roles_id' => RolesRepository::getByNameFromCompany(
                    name: RolesEnums::ADMIN->value,
                    app: $this->channelDto->apps,
                )->id,
            ]
        );

        return $channel;
    }
}
