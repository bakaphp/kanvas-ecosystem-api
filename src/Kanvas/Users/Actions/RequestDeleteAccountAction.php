<?php

declare(strict_types=1);

namespace Kanvas\Users\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\RequestDeletedAccount;
use Kanvas\Users\Models\Users;

class RequestDeleteAccountAction
{
    public function __construct(
        public Apps $app,
        public Users $user,
    ) {
    }

    public function execute(): bool
    {
        RequestDeletedAccount::create([
            'apps_id' => $this->app->getId(),
            'users_id' => $this->user->getId(),
            'email' => $this->user->email,
            'request_date' => date('Y-m-d H:i:s'),
        ]);

        //soft delete anything messages the user has created.
        $userMessages = Message::fromApp($this->app)
            ->where('users_id', $this->user->getId())->get();

        foreach ($userMessages as $message) {
            $message->is_deleted = 1;
            $message->is_public = 0;
            $message->save();
            $message->unsearchable();
        }

        $this->user->unsearchable();

        return true;
    }
}
