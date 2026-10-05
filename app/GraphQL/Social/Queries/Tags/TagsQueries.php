<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Queries\Tags;

use Baka\Enums\StateEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Social\Tags\Models\Tag;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;

class TagsQueries
{
    public function getTagsBuilder(mixed $root, array $args): mixed
    {
        $app = app(Apps::class);
        $systemModule = SystemModulesRepository::getByModelName($root::class, $app);

        return Tag::whereHas('taggables', function ($query) use ($root, $systemModule) {
            $query->where('entity_id', $root->taggableKey());
            $query->where('taggable_type', $systemModule->model_name);
            $query->where('apps_id', $systemModule->apps_id);
        })->where('is_deleted', StateEnums::NO->getValue());
    }

    /**
     * A user's tags live on their membership in the viewer's current company (see TagUserTool),
     * so one company's tags on a shared user never show up in another.
     */
    public function getUserTagsBuilder(Users $root, array $args): mixed
    {
        try {
            $membership = UsersRepository::belongsToThisApp(
                $root,
                app(Apps::class),
                auth()->user()->getCurrentCompany()
            );
        } catch (ModelNotFoundException) {
            return Tag::query()->whereRaw('1 = 0');
        }

        return $this->getTagsBuilder($membership, $args);
    }
}
