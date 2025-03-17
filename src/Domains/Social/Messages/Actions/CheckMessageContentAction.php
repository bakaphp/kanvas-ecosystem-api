<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Actions;

use Exception;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\SightEngine\Services\ContentModerationService;

class CheckMessageContentAction
{
    /**
     * __construct
     *
     * @return void
     */
    public function __construct(
        private array $message,
        private Apps $app,
    ) {
    }

    /**
     * execute
     *
     * @return void
     */
    public function execute()
    {
        $messageContent = is_array($this->message) ? $this->message : json_decode($this->message, true);
        if ($this->app->get('enable-image-moderation')) {
            $imageContentModerationService = (new ContentModerationService())->scanImage($messageContent[$this->app->get('image-moderation-field')]);
            if (in_array(true, $imageContentModerationService, true)) {
                throw new Exception('Image content moderation: inappropriate content detected.');
            }
        }

        if ($this->app->get('enable-text-moderation')) {
            $textContentModerationService = (new ContentModerationService())->scanText($messageContent[$this->app->get('text-moderation-field')]);
            if (in_array(true, $textContentModerationService, true)) {
                throw new Exception('Text content moderation: inappropriate content detected.');
            }
        }
        
    }
}
