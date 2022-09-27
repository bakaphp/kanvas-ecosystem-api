<?php

namespace Kanvas\Notifications;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class UserSignUp extends Notification implements ShouldQueue{
    use Queueable;

}