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

        // Handle case where JSON decode fails
        if (! is_array($messageContent)) {
            return false;
        }

        // Check image moderation
        if ($this->app->get('enable-image-moderation')) {
            $imageModerationField = $this->app->get('image-moderation-field');

            if (! empty($imageModerationField) &&
                array_key_exists($imageModerationField, $messageContent) &&
                ! empty($messageContent[$imageModerationField])) {
                $imageContentModerationService = (new ContentModerationService())->scanImage($messageContent[$imageModerationField]);
                if (in_array(true, $imageContentModerationService, true)) {
                    return true;
                }
            }
        }

        // Check text moderation
        if ($this->app->get('enable-text-moderation')) {
            $textModerationField = $this->app->get('text-moderation-field');

            if (! empty($textModerationField) &&
                array_key_exists($textModerationField, $messageContent) &&
                ! empty($messageContent[$textModerationField])) {
                $textContentModerationService = (new ContentModerationService())->scanText($messageContent[$textModerationField]);
                if (in_array(true, $textContentModerationService, true)) {
                    return true;
                }
            }
        }

        return false;
    }
}
