<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Google\Actions;

use Kanvas\Connectors\Google\Services\GeminiTagService;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Tags\Models\Tag;
use Kanvas\Workflow\Enums\WorkflowEnum;

class GenerateMessageTagAction
{
    public function __construct(
        protected Message $message
    ) {
    }

    public function execute(
        ?string $textLookupKey = null,
        bool $limitByCompany = false,
        int $totalTags = 3,
        array $tags = []
    ): Message {
        if (empty($tags)) {
            $tags = Tag::fromApp($this->message->app)->notDeleted();

            if ($limitByCompany) {
                $tags->fromCompany($this->message->company);
            }

            $tags = $tags->get()->pluck('name')->toArray();
        }

        $messageData = $this->message->message;

        //$messageText = $textLookupKey !== null ? data_get($messageData, $textLookupKey) : $messageData; //ai_nugged.nugget
        $messageText = ! is_array($messageData) ? $messageData : json_encode($messageData);

        if (empty($messageText) || ! is_string($messageText)) {
            return $this->message;
        }

        $geminiTagService = new GeminiTagService();
        $tags = $geminiTagService->generateTags($messageText, $tags, $totalTags);

        if (! empty($tags)) {
            $this->message->addTags(
                $tags
            );
            $this->message->fireWorkflow(
                WorkflowEnum::UPDATED->value,
                true,
                ['app' => $this->message->app]
            );
        }

        $this->message->refresh();

        return $this->message;
    }
}
