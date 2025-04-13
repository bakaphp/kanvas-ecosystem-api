<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Observers;

use Kanvas\Social\Messages\Actions\UpdateInteractionCount;
use Kanvas\Social\Messages\Models\UserMessage;

class UserMessageObserver
{
    public function created(UserMessage $userMessage): void
    {
        if ($userMessage->message) {
            (new UpdateInteractionCount($userMessage->message))->execute();
        }
    }

    public function updated(UserMessage $userMessage): void
    {
        if ($userMessage->message) {
            (new UpdateInteractionCount($userMessage->message))->execute();
        }
    }

    public function deleted(UserMessage $userMessage): void
    {
        if ($userMessage->message) {
            (new UpdateInteractionCount($userMessage->message))->execute();
        }
    }
}
