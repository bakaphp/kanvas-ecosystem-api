<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Builders\UsersLists;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\UsersLists\Models\UserList as ModelUserList;
use Laravel\Scout\Builder as ScoutBuilder;

class SearchBuilder
{
    /**
     * Build the search query.
     *
     * @param string $search
     */
    public function search(mixed $builder, mixed $req): Builder|ScoutBuilder
    {
        $search = ModelUserList::search($req['search'])
                  ->where('is_public', $req['is_public'] ?? false)
                  ->where('apps_id', app(Apps::class)->id);

        return $search;
    }
}
