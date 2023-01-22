<?php

namespace Kanvas\Notifications\Templates;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Models\Users;

class Mail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * The user instance.
     *
     * @var Users
     */
    public $user;

    /**
     * The app instance.
     *
     * @var Apps
     */
    public $app;

    /**
     * Create a new message instance.
     *
     * @param  Users  $user
     *
     * @return void
     */
    public function __construct(Users $user)
    {
        $this->user = $user;
        $this->app = app(Apps::class);
    }
}
