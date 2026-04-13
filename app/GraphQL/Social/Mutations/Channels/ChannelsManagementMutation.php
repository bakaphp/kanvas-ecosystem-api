<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Mutations\Channels;

use Baka\Support\Str;
use Exception;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Exceptions\AuthenticationException;
use Kanvas\Social\Channels\Actions\ClearChannelMessagesAction;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Channels\Repositories\ChannelRepository;
use Kanvas\Social\Enums\AppEnum;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;

class ChannelsManagementMutation
{
    public function createChannel(mixed $rootValue, array $request): Channel
    {
        $systemModule = SystemModulesRepository::getByUuidOrModelName($request['input']['entity_namespace_uuid']);
        $channelDto = new ChannelDto(
            apps: app(Apps::class),
            companies: auth()->user()->getCurrentCompany(),
            users: auth()->user(),
            name: $request['input']['name'],
            description: $request['input']['description'],
            entity_id: $request['input']['entity_id'],
            entity_namespace: $systemModule->model_name,
            slug: $request['input']['slug'] ?? Str::slug($request['input']['name']),
        );

        $createChannel = new CreateChannelAction($channelDto);
        $channel = $createChannel->execute();

        return $channel;
    }

    public function updateChannel(mixed $rootValue, array $request): Channel
    {
        $app = app(Apps::class);
        $channel = ChannelRepository::getById(
            (int)$request['id'],
            auth()->user(),
            $app
        );
        $systemModule = SystemModulesRepository::getByUuidOrModelName($request['input']['entity_namespace_uuid']);

        $channel->name = $request['input']['name'];
        $channel->description = $request['input']['description'];
        $channel->entity_id = $request['input']['entity_id'];
        $channel->title = $request['input']['title'] ?? $channel->title;
        $channel->entity_namespace = $systemModule->uuid;
        $channel->updateOrFail();

        return $channel;
    }

    public function deleteChannel(mixed $rootValue, array $request): Channel
    {
        $app = app(Apps::class);

        $channel = ChannelRepository::getByIdBuilder(auth()->user(), $app)
            //->where('channel_users.roles_id', 1)
            ->findOrFail($request['id']);

        $channel->delete();

        return $channel;
    }

    public function attachUserToChannel(mixed $rootValue, array $request): Channel
    {
        $app = app(Apps::class);
        $channel = ChannelRepository::getById(
            (int)$request['input']['channel_id'],
            auth()->user(),
            $app
        );
        $user = $app->get(AppEnum::ALLOW_APP_WIDE_USER_CHANNEL_ASSIGNMENT->value)
            ? UsersRepository::getUserOfAppById((int) $request['input']['user_id'], $app)
            : Users::getByIdFromCompany($request['input']['user_id'], auth()->user()->getCurrentCompany());

        try {
            $roles = RolesRepository::getByMixedParamFromCompany(
                $request['input']['roles_id'],
                auth()->user()->getCurrentCompany(),
                $app
            );
        } catch (Exception $e) {
            $roles = RolesRepository::getByMixedParamFromCompany(
                RolesEnums::USER->value,
                auth()->user()->getCurrentCompany(),
                $app
            );
        }
        $channel->users()->syncWithoutDetaching([
            $user->id => [
                'roles_id' => $roles->id,
            ],
        ]);

        return $channel;
    }

    public function detachUserToChannel(mixed $rootValue, array $request): Channel
    {
        $app = app(Apps::class);

        $channel = ChannelRepository::getById(
            (int) $request['channel_id'],
            auth()->user(),
            $app
        );
        $user = Users::getById($request['user_id']);
        $channel->users()->detach($user->id);

        return $channel;
    }

    public function clearMessages(mixed $rootValue, array $request): bool
    {
        $user = auth()->user();

        if (! $user->isAdmin()) {
            throw new AuthenticationException('Only app admins can clear channel messages');
        }

        $app = app(Apps::class);
        $channel = Channel::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $user->getCurrentCompany()->getId())
            ->findOrFail((int) $request['channel_id']);

        return new ClearChannelMessagesAction($channel)->execute();
    }
}
