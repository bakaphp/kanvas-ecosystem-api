<?php
namespace Kanvas\Notifications\Templates;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Kanvas\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Kanvas\Users\Models\Users;
use Kanvas\Templates\Models\Templates;
use Illuminate\Support\Facades\Blade;

class UserSignUp extends Notification implements ShouldQueue
{
    use Queueable;

    public string $templateName = 'user-signup';

    /**
     * __construct
     *
     * @param  Users $user
     * @return void
     */
    public function __construct(Users $user)
    {
        $this->entity = $user;
        $this->setType('users');
    }

    /**
     * via
     *
     * @return array
     */
    public function via(): array
    {
        return [...parent::via(), 'mail'];
    }

    /**
     * getData
     *
     * @return array
     */
    public function getData(): array
    {
        return [
            'name' => 'Barrett Blair',
        ];
    }
}
