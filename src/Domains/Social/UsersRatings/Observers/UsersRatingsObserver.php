<?php

namespace Kanvas\Social\UsersRatings\Observers;

use Kanvas\Social\UsersRatings\Models\UsersRatings;

class UsersRatingsObserver
{
    protected function calcAverageRating(UsersRatings $usersRatings): void
    {
        $rating = UsersRatings::where('entity_id', $usersRatings->entity_id)
            ->where('system_modules_id', $usersRatings->system_modules_id)
            ->avg('rating');
        $usersRatings->entity()->update(['rating' => $rating]);
    }

    public function created(UsersRatings $usersRatings): void
    {
        $this->calcAverageRating($usersRatings);
    }

    public function updated(UsersRatings $usersRatings): void
    {
        $this->calcAverageRating($usersRatings);
    }
}
