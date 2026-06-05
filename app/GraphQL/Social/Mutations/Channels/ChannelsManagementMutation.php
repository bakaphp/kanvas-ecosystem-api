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
        /** @var array $input */
        $input = $request['input'];
        $systemModule = SystemModulesRepository::getByUuidOrModelName($input['entity_namespace_uuid']);
        $channelDto = new ChannelDto(
            apps: app(Apps::class),
            companies: auth()->user()->getCurrentCompany(),
            users: auth()->user(),
            name: $input['name'],
            description: $input['description'],
            entity_id: $input['entity_id'],
            entity_namespace: $systemModule->model_name,
            slug: $input['slug'] ?? Str::slug($input['name']),
            metadata: $input['metadata'] ?? null,
            tags: $input['tags'] ?? [],
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
        /** @var array $input */
        $input = $request['input'];
        $systemModule = SystemModulesRepository::getByUuidOrModelName($input['entity_namespace_uuid']);

        $channel->name = $input['name'];
        $channel->description = $input['description'];
        $channel->entity_id = $input['entity_id'];
        $channel->title = $input['title'] ?? $channel->title;
        $channel->entity_namespace = $systemModule->uuid;

        if (array_key_exists('metadata', $input)) {
            $channel->metadata = $input['metadata'];
        }

        $channel->updateOrFail();

        if (! empty($input['tags'])) {
            $channel->syncTags($input['tags']);
        }

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
        $channelQuery = Channel::query()
            ->where('apps_id', $app->getId());

        if (! $app->get(AppEnum::ALLOW_APP_WIDE_USER_CHANNEL_ASSIGNMENT->value)) {
            $channelQuery->where('companies_id', $user->getCurrentCompany()->getId());
        }

        $channel = $channelQuery->findOrFail((int) $request['channel_id']);

        return new ClearChannelMessagesAction($channel)->execute();
    }
}
