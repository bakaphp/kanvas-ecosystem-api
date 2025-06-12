<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\SightEngine\Services\ContentModerationService;

class CheckMessageContentAction
{
    public function __construct(
        private array $message,
        private Apps $app,
    ) {
    }

    public function execute(): bool
    {
        $messageContent = is_array($this->message) ? $this->message : json_decode($this->message, true);
<<<<<<< HEAD
        if ($this->app->get('enable-image-moderation') && $this->app->get('image-moderation-field')) {
=======
        if ($this->app->get('enable-image-moderation') && $this->app->get('image-moderation-field') !== null && ! empty($messageContent[$this->app->get('image-moderation-field')])) {
>>>>>>> 40b411667f87df7a2ad40e45d781bb4d08d4265c
            $imageContentModerationService = (new ContentModerationService())->scanImage($messageContent[$this->app->get('image-moderation-field')]);
            if (in_array(true, $imageContentModerationService, true)) {
                return true;
            }
        }

<<<<<<< HEAD
        if ($this->app->get('enable-text-moderation') && $this->app->get('text-moderation-field')) {
=======
        if ($this->app->get('enable-text-moderation') && $this->app->get('text-moderation-field') !== null && ! empty($messageContent[$this->app->get('text-moderation-field')])) {
>>>>>>> 40b411667f87df7a2ad40e45d781bb4d08d4265c
            $textContentModerationService = (new ContentModerationService())->scanText($messageContent[$this->app->get('text-moderation-field')]);
            if (in_array(true, $textContentModerationService, true)) {
                return true;
            }
        }

        return false;
    }
}
