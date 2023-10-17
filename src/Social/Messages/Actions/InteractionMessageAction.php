<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Actions;

use Kanvas\Social\Messages\Enums\ActivityTypeEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;

class InteractionMessageAction
{
    public function __construct(
        protected Message $message,
        protected Users $user,
        protected ActivityTypeEnum $activityType
    ) {
    }

    public function execute()
    {
        if($this->activityType === ActivityTypeEnum::LIKE) {
            $text = 'liked this message';
        } elseif($this->activityType === ActivityTypeEnum::SAVE) {
            $text = 'saved this message';
        } elseif($this->activityType === ActivityTypeEnum::SHARE) {
            $text = 'shared this message';
        } elseif($this->activityType === ActivityTypeEnum::REPORT) {
            $text = 'reported this message';
        }


        $userMessage = (new CreateUserMessageAction(
            $this->message,
            $this->user,
            [
                'entity_namespace' => $this->message::class,
                'username' => $this->user->displayname,
                'type' => $this->activityType->value,
                'text' => $text,
            ]
        ))->execute();

        if($this->activityType === ActivityTypeEnum::LIKE) {
            $userMessage->is_liked = ! $userMessage->is_liked;
        } elseif($this->activityType === ActivityTypeEnum::SAVE) {
            $userMessage->is_saved = ! $userMessage->is_saved;
        } elseif($this->activityType === ActivityTypeEnum::SHARE) {
            $userMessage->is_shared = ! $userMessage->is_shared;
        } elseif($this->activityType === ActivityTypeEnum::REPORT) {
            $userMessage->is_reported = ! $userMessage->is_reported;
        }

        $userMessage->saveOrFail();

        return $userMessage;
    }
}
