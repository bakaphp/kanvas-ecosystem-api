<?php

namespace Kanvas\Notifications\Templates;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Kanvas\Notifications\Notification;

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
