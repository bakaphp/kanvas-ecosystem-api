<?php

namespace Kanvas\Notifications\Templates;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Kanvas\Notifications\Notification;
use Kanvas\Users\Models\Users;

class ResetPassword extends Notification implements ShouldQueue
{
    use Queueable;

    public ?string $templateName = 'reset-password';

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
