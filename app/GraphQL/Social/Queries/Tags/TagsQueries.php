<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Queries\Tags;

use Baka\Enums\StateEnums;
use Kanvas\Social\Tags\Models\Tag;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;

class TagsQueries
{
    public function getTagsBuilder(mixed $root, array $args): mixed
    {
        $systemModule = SystemModulesRepository::getByModelName($root::class);

        return Tag::whereHas('taggables', function ($query) use ($root, $systemModule) {
            $query->where('entity_id', $root->getKey());
            $query->where('entity_namespace', $systemModule->model_name);
        })->where('is_deleted', StateEnums::NO->getValue());
    }
}
