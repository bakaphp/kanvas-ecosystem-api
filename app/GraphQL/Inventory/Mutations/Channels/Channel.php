<?php
declare(strict_types=1);

namespace App\GraphQL\Inventory\Mutations\Channels;

use Kanvas\Inventory\Channels\Actions\CreateChannel;
use Kanvas\Inventory\Channels\DataTransferObject\Channels as ChannelsDto;
use Kanvas\Inventory\Channels\Models\Channels as ChannelsModel;
use Kanvas\Inventory\Channels\Repositories\ChannelRepository;

class Channel
{
    /**
     * create.
     *
     * @param  mixed $rootValue
     * @param  array $args
     *
     * @return ChannelsModel
     */
    public function create(mixed $rootValue, array $request) : ChannelsModel
    {
        $data = $request['input'];
        $dto = ChannelsDto::viaRequest($data);
        $channel = (new CreateChannel($dto, auth()->user()))->execute();
        return $channel;
    }

    /**
     * update.
     *
     * @param  mixed $rootValue
     * @param  array $request
     *
     * @return ChannelsModel
     */
    public function update(mixed $rootValue, array $request) : ChannelsModel
    {
        $id = $request['id'];
        $data = $request['input'];
        $channel = ChannelRepository::getById($id, auth()->user()->getCurrentCompany());
        $channel->update($data);
        return $channel;
    }

    /**
     * delete.
     *
     * @param  mixed $rootValue
     * @param  array $request
     *
     * @return bool
     */
    public function delete(mixed $rootValue, array $request) : bool
    {
        $id = $request['id'];
        $channel = ChannelRepository::getById($id, auth()->user()->getCurrentCompany());
        return $channel->delete();
    }
}
