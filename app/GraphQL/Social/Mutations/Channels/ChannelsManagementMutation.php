<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Mutations\Channels;

use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Channels\Repositories\ChannelRepository;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Models\Users;

class ChannelsManagementMutation
{
    public function createChannel(mixed $rootValue, array $request): Channel
    {
        $systemModule = SystemModules::getByUuid($request['input']['entity_namespace_uuid']);
        $channelDto = new ChannelDto(
            users: auth()->user(),
            name: $request['input']['name'],
            description: $request['input']['description'],
            entity_id: $request['input']['entity_id'],
            entity_namespace: $systemModule->uuid,
        );

        $createChannel = new CreateChannelAction($channelDto);
        $channel = $createChannel->execute();

        return $channel;
    }

    public function updateChannel(mixed $rootValue, array $request): Channel
    {
        $channel = ChannelRepository::getById((int)$request['id'], auth()->user());
        $systemModule = SystemModules::getByUuid($request['input']['entity_namespace_uuid']);

        $channel->name = $request['input']['name'];
        $channel->description = $request['input']['description'];
        $channel->entity_id = $request['input']['entity_id'];
        $channel->entity_namespace = $systemModule->uuid;
        $channel->updateOrFail();

        return $channel;
    }

    public function deleteChannel(mixed $rootValue, array $request): Channel
    {
        $channel = ChannelRepository::getByIdBuilder(auth()->user())
            ->where('channel_users.roles_id', 1)
            ->find($request['id']);

        $channel->delete();

        return $channel;
    }

    public function attachUserToChannel(mixed $rootValue, array $request): Channel
    {
        $channel = ChannelRepository::getById((int)$request['channel_id'], auth()->user());
        $user = Users::getByIdFromCompany($request['user_id'], auth()->user()->getCurrentCompany());

        $channel->users()->attach($user->id, ['roles_id' => $request['roles_id']]);

        return $channel;
    }

    public function detachUserToChannel(mixed $rootValue, array $request): Channel
    {
        $channel = ChannelRepository::getById((int)$request['channel_id'], auth()->user());

        $user = Users::getById($request['user_id']);

        $channel->users()->detach($user->id);

        return $channel;
    }
}
