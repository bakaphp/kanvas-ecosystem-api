<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Queries\Tags;

use Baka\Enums\StateEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Tags\Models\Tag;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;

class TagsQueries
{
    public function getTagsBuilder(mixed $root, array $args): mixed
    {
        $app = app(Apps::class);
        $systemModule = SystemModulesRepository::getByModelName($root::class, $app);

        return Tag::whereHas('taggables', function ($query) use ($root, $systemModule) {
            $query->where('entity_id', $root->getKey());
            $query->where('entity_namespace', $systemModule->model_name);
            $query->where('apps_id', $systemModule->apps_id);
        })->where('is_deleted', StateEnums::NO->getValue());
    }
}
