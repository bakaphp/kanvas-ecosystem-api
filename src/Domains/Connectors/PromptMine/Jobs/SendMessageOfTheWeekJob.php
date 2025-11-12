<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\PromptMine\Enums\NotificationTemplateEnum;
use Kanvas\Connectors\PromptMine\Notifications\MessageOfTheWeekNotification;
use Kanvas\Social\Messages\Repositories\MessagesRepository;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Users\Models\Users;

class SendMessageOfTheWeekJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use KanvasJobsTrait;

    public function __construct(
        protected Apps $app,
        protected Users $user,
        protected MessageType $messageType,
        protected array $via,
    ) {
    }

    /**
     * Send message notifications to all followers of a user.
     */
    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        $messageOfTheWeek = MessagesRepository::getMostPopularMessageByTotalLikes($this->app, $this->messageType);
        if ($messageOfTheWeek === null || ! array_key_exists('title', $messageOfTheWeek->message)) {
            return;
        }

        $title = html_entity_decode($messageOfTheWeek->message['title'], ENT_QUOTES, 'UTF-8');
        $messageOfTheWeekNotification = new MessageOfTheWeekNotification(
            $this->user,
            [
                'push_template' => NotificationTemplateEnum::PUSH_WEEKLY_FAVORITE_PROMPT->value,
                'title' => 'AI creation of the Week',
                'message' => "$title — Try it now and keep the momentum going."
            ],
            $this->via
        );
        $messageOfTheWeekNotification->setData([
            'destination_id' => $messageOfTheWeek->parent ? $messageOfTheWeek->parent->getId() : $messageOfTheWeek->getId(),
            'destination_type' => 'MESSAGE',
            'destination_event' => 'NEW_MESSAGE',
        ]);
        $messageOfTheWeekNotification->setPushTemplateName(NotificationTemplateEnum::PUSH_WEEKLY_FAVORITE_PROMPT->value);
        $this->user->notify($messageOfTheWeekNotification);
    }
}
