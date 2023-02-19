<?php

namespace Kanvas\Notifications\Templates;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Kanvas\Notifications\Notification;
use Kanvas\Users\Models\Users;

class UserSignUp extends Notification implements ShouldQueue
{
    use Queueable;

    public ?string $templateName = 'user-signup';

    /**
     * via.
     *
     * @return array
     */
    public function via(): array
    {
        return [...parent::via(), 'mail'];
    }

}
