<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Actions;

use Exception;
use Kanvas\Social\Messages\Models\Message;
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
        if ($this->app->get('enable-image-moderation')) {
            $imageContentModerationService = (new ContentModerationService())->scanImage($messageContent[$this->app->get('image-moderation-field')]);
            if (in_array(true, $imageContentModerationService, true)) {
                return true;
            }
        }

        if ($this->app->get('enable-text-moderation')) {
            $textContentModerationService = (new ContentModerationService())->scanText($messageContent[$this->app->get('text-moderation-field')]);
            if (in_array(true, $textContentModerationService, true)) {
                return true;
            }
        }

        return false;
    }
}
