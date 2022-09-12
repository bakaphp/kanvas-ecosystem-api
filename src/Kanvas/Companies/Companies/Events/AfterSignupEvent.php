<?php

namespace Kanvas\Companies\Companies\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kanvas\Companies\Companies\Models\Companies;
use Kanvas\Users\Users\Models\Users;

class AfterSignupEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The company instance.
     *
     * @var Companies
     */
    public $company;

    /**
     * The logged in user data.
     *
     * @var Companies
     */
    public $user;

    /**
     * Create a new event instance.
     *
     * @param  Companies  $company
     *
     * @return void
     */
    public function __construct(Companies $company, Users $userData)
    {
        $this->company = $company;
        $this->user = $userData;
    }
}
