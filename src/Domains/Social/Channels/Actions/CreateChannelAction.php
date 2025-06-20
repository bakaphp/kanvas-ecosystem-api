<?php

declare(strict_types=1);

namespace Kanvas\Social\Channels\Actions;

use Baka\Support\Str;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\SystemModules\Models\SystemModules;

class CreateChannelAction
{
    public function __construct(
        protected ChannelDto $channelDto
    ) {
    }

    public function execute(): Channel
    {
        $legacySystemModule = SystemModules::getLegacyNamespace($this->channelDto->entity_namespace);
        // Determine the slug based on legacy or new namespace
        $slug = $this->channelDto->slug ?? Str::slug($this->channelDto->name);

        // Support both legacy and new entity_namespace for slug uniqueness
        $channel = Channel::where('apps_id', $this->channelDto->apps->id)
            ->where('companies_id', $this->channelDto->companies->id)
            ->where('slug', $slug)
            ->whereIn('entity_namespace', [
                $this->channelDto->entity_namespace,
                $legacySystemModule,
            ])
            ->first();

        if (! $channel) {
            $channel = Channel::create([
            'apps_id' => $this->channelDto->apps->id,
            'companies_id' => $this->channelDto->companies->id,
            'slug' => $slug,
            'entity_id' => $this->channelDto->entity_id,
            'entity_namespace' => $this->channelDto->entity_namespace,
            'users_id' => $this->channelDto->users->id,
            'name' => $this->channelDto->name,
            'description' => $this->channelDto->description,
            ]);
        }

        $channel->users()->syncWithoutDetaching([
            $this->channelDto->users->id => [
                'roles_id' => RolesRepository::getByNameFromCompany(
                    name: RolesEnums::ADMIN->value,
                    app: $this->channelDto->apps,
                )->id,
            ],
        ]);

        return $channel;
    }
}
