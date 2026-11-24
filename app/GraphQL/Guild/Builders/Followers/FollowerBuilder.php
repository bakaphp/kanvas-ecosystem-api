<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Builders\Followers;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Followers\Repositories\FollowersRepository;
use Kanvas\Guild\Leads\Models\Lead;

class FollowerBuilder
{
    public function getFollowers(Lead|People $root): Builder
    {
        return FollowersRepository::getFollowersBuilder($root);
    }
}
