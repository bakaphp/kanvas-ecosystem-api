<?php
declare(strict_types=1);
namespace Kanvas\Notifications\Templates;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Kanvas\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Kanvas\Users\Models\Users;
use Kanvas\Templates\Models\Templates;
use Illuminate\Support\Facades\Blade;
use Kanvas\Users\Invites\Models\UsersInvite;

class Invite extends Notification
{

    public string $templateName = 'users-invite';

    /**
     * __construct
     *
     * @param  Invites $user
     * @return void
     */
    public function __construct(UsersInvite $invite)
    {
        $this->entity = $invite;
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
            'name' => "{$this->entity->firstname} {$this->entity->lastname}",
        ];
    }
}
