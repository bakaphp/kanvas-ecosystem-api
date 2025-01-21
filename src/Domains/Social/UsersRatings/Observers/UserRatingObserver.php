<?php

namespace Kanvas\Social\UsersRatings\Observers;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Kanvas\Social\UsersRatings\Models\UserRating;

class UserRatingObserver implements ShouldQueue
{
    use Queueable;

    protected function calcAverageRating(UserRating $userRating): void
    {
        $rating = UserRating::where('entity_id', $userRating->entity_id)
            ->where('system_modules_id', $userRating->system_modules_id)
            ->avg('rating');
        $userRating->entity()->update(['rating' => $rating]);
    }

    public function created(UserRating $userRating): void
    {
        $this->calcAverageRating($userRating);
    }

    public function updated(UserRating $userRating): void
    {
        $this->calcAverageRating($userRating);
    }
}
