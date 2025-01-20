<?php

namespace Kanvas\Social\UsersRatings\Observers;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Kanvas\Social\UsersRatings\Models\UserRating;

class UserRatingObserver implements ShouldQueue
{
    use Queueable;

    protected function calcAverageRating(UserRating $usersRatings): void
    {
        $rating = UserRating::where('entity_id', $usersRatings->entity_id)
            ->where('system_modules_id', $usersRatings->system_modules_id)
            ->avg('rating');
        $usersRatings->entity()->update(['rating' => $rating]);
    }

    public function created(UserRating $usersRatings): void
    {
        $this->calcAverageRating($usersRatings);
    }

    public function updated(UserRating $usersRatings): void
    {
        $this->calcAverageRating($usersRatings);
    }
}
