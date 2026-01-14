<?php

declare(strict_types=1);

namespace Kanvas\Users\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\RequestDeletedAccount;
use Kanvas\Users\Models\Users;
use Kanvas\Social\Messages\Models\UsersMessages;

class RestoreReactivatedAccountContentAction
{
    public function __construct(
        public Apps $app,
        public Users $user,
    ) {}

    public function execute(): bool
    {
        $this->restoreUserMessages();
        $this->user->searchable();

        return true;
    }

    private function restoreUserMessages(): void
    {
        // Why this? because we only delete the messages themselves when the account deletion request is made and not the records in users_messages to know which were active before deletion request
        Message::fromApp($this->app)
            ->join('users_messages', 'messages_id', '=', 'messages.id')
            ->where('users_id', $this->user->getId())
            ->where('users_messages.users_id', $this->user->getId())
            ->where('users_messages.is_deleted', 0)
            ->where('is_deleted', 1)
            ->where('is_public', 0)
            ->chunk(100, function ($messages) {
                foreach ($messages as $message) {
                    $message->is_deleted = 0;
                    $message->is_public = 1;
                    $message->save();
                    $message->searchable();
                }
            });
    }
}
