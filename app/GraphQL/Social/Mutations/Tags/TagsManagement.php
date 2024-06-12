<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Mutations\Tags;

use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Follows\Actions\FollowAction;
use Kanvas\Social\Follows\Actions\UnFollowAction;
use Kanvas\Social\Follows\Repositories\UsersFollowsRepository;
use Kanvas\Social\Tags\Actions\CreateTagAction;
use Kanvas\Social\Tags\DataTransferObjects\Tag as TagData;
use Kanvas\Social\Tags\Models\Tag;
use Kanvas\SystemModules\DataTransferObject\SystemModuleEntityInput;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;

class TagsManagement
{
    public function create(mixed $root, array $request): Tag
    {
        $request['input']['app'] = app(Apps::class);
        $request['input']['user'] = auth()->user();
        $request['input']['company'] = auth()->user()->getCurrentCompany();

        $dto = TagData::from($request['input']);

        return (new CreateTagAction($dto))->execute();
    }

    public function update(mixed $root, array $request): Tag
    {
        $app = app(Apps::class);

        $tag = Tag::when(! auth()->user()->isAdmin(), function ($query) {
            return $query->where('users_id', auth()->user()->getId());
        })->where('id', $request['id'])
            ->fromApp($app)
            ->firstOrFail();

        $tag->update($request['input']);

        return $tag;
    }

    public function delete(mixed $root, array $request): bool
    {
        $app = app(Apps::class);
        $tag = Tag::when(! auth()->user()->isAdmin(), function ($query) {
            return $query->where('users_id', auth()->user()->getId());
        })
            ->where('id', $request['id'])
            ->fromApp($app)
            ->firstOrFail();


        return $tag->delete();
    }

    public function follow(mixed $root, array $request): bool
    {
        $app = app(Apps::class);
        $tag = Tag::getById($request['id'], $app);
        $isFollowing = UsersFollowsRepository::isFollowing(auth()->user(), $tag);
        if ($isFollowing) {
            return (new UnFollowAction(auth()->user(), $tag))->execute();
        } else {
            return (bool)(new FollowAction(auth()->user(), $tag))->execute();
        }
    }

    public function attachTagToEntity(mixed $root, array $request): bool
    {
        $app = app(Apps::class);
        $tag = Tag::getById($request['input']['tag_id'], $app);
        $user = auth()->user();

        $systemModule = SystemModules::getByUuid($request['input']['system_module_uuid'], $app);

        //$entity = $systemModule->model_name::getById((int)$request['input']['entity_id'], $app);
        $entity = SystemModulesRepository::getEntityFromInput(
            new SystemModuleEntityInput(
                $systemModule->name,
                $systemModule->uuid,
                $request['input']['entity_id']
            ),
            $user,
            useCompanyReference: false
        );

        $tag->entities()->attach($entity->getId(), [
            //'entity_namespace' => $systemModule->model_name,
            'apps_id' => $tag->apps_id,
            'companies_id' => $user->getCurrentCompany()->getId(),
            'users_id' => $user->getId(),
            'taggable_type' => $systemModule->model_name,
        ]);

        return true;
    }
}
