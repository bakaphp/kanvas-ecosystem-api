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
        if($req['search']){
            $modelSearch = ModelUserList::search($req['search']);
        }else{
            $modelSearch = ModelUserList::search();
        }
        $search = $modelSearch->whereIn('is_public', [
            key_exists('is_public', $req) ? $req['is_public'] : true,
            key_exists('is_public', $req) ? $req['is_public'] : 1,
        ]);

        return $search;
    }
}
