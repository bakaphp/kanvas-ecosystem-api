<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Mutations\Channels;

use Kanvas\Inventory\Channels\Actions\CreateChannel;
use Kanvas\Inventory\Channels\DataTransferObject\Channels as ChannelsDto;
use Kanvas\Inventory\Channels\Models\Channels as ChannelsModel;
use Kanvas\Inventory\Channels\Repositories\ChannelRepository;

class ChannelMutation
{
    /**
     * create.
     */
    public function create(mixed $rootValue, array $request): ChannelsModel
    {
        $data = $request['input'];
        $dto = ChannelsDto::viaRequest($data);
        $channel = (new CreateChannel($dto, auth()->user()))->execute();

        return $channel;
    }

    /**
     * update.
     */
    public function update(mixed $rootValue, array $request): ChannelsModel
    {
        $id = $request['id'];
        $data = $request['input'];
        $channel = ChannelRepository::getById((int) $id, auth()->user()->getCurrentCompany());
        $channel->update($data);

        return $channel;
    }

    /**
     * delete.
     */
    public function delete(mixed $rootValue, array $request): bool
    {
        $id = $request['id'];
        $channel = ChannelRepository::getById((int) $id, auth()->user()->getCurrentCompany());

        return $channel->delete();
    }

    /**
     * Unpublish all variants from channel.
     */
    public function unPublishAllVariantsFromChannel(mixed $rootValue, array $request): bool
    {
        $id = $request['id'];
        $channel = ChannelRepository::getById((int) $id, auth()->user()->getCurrentCompany());

        return $channel->unPublishAllVariants();
    }
}
