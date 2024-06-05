<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Mutations\Tags;

use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Follows\Actions\FollowAction;
use Kanvas\Social\Follows\Actions\UnFollowAction;
use Kanvas\Social\Follows\Repositories\UsersFollowsRepository;
use Kanvas\Social\Tags\Actions\CreateTagAction;
use Kanvas\Social\Tags\DataTransferObjects\Tag as TagData;
use Kanvas\Social\Tags\Models\Tag;
use Kanvas\Users\Models\UsersAssociatedApps;

class TagsManagement
{
    public function create(mixed $root, array $request): Tag
    {
        $appId = key_exists('apps_id', $request['input']) ? $request['input']['apps_id'] : app(Apps::class)->getId();

        $app = UsersAssociatedApps::where('users_id', auth()->user()->getId())
                ->where('apps_id', $appId)
                ->firstOrFail();

        $request['input']['slug'] = key_exists('slug', $request['input']) ? $request['input']['slug'] : Str::slug($request['input']['name']);
        $request['input']['app'] = $app->app;
        $request['input']['user'] = auth()->user();
        $request['input']['company'] = auth()->user()->getCurrentCompany();

        $dto = TagData::from($request['input']);

        return (new CreateTagAction($dto))->execute();
    }

    public function update(mixed $root, array $request): Tag
    {
        $tag = Tag::where('users_id', auth()->user()->getId())
            ->where('id', $request['id'])
            ->firstOrFail();

        $tag->update($request['input']);

        return $tag;
    }

    public function delete(mixed $root, array $request): bool
    {
        $tag = Tag::where('users_id', auth()->user()->getId())
            ->where('id', $request['id'])
            ->firstOrFail();

        return $tag->delete();
    }

    public function follow(mixed $root, array $request): bool
    {
        $tag = Tag::find($request['id']);
        $isFollowing = UsersFollowsRepository::isFollowing(auth()->user(), $tag);
        if ($isFollowing) {
            return (new UnFollowAction(auth()->user(), $tag))->execute();
        } else {
            return (bool)(new FollowAction(auth()->user(), $tag))->execute();
        }
    }
}
