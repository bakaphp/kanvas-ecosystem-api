<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\CustomFields;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Companies\Models\CompaniesSettings;
use Kanvas\Users\Models\UserConfig;
use Kanvas\Users\Models\Users;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class HashFieldsQuery
{
    /**
     * Get all file from a entity tied to the graph
     */
    public function getAllByGraphType(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {
        if ($root instanceof Users) {
            $customFields = UserConfig::where('users_id', '=', $root->getKey())->where('is_public', '=', 1);
        } else {
            $customFields = CompaniesSettings::where('companies_id', '=', $root->getKey())->where('is_public', '=', 1);
        }

        return $customFields;
    }
}
