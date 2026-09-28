<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WordPress\DataTransferObject;

use Kanvas\Connectors\WordPress\Enums\CustomFieldEnum;
use Kanvas\Connectors\WordPress\Enums\DuplicateMatchEnum;
use Kanvas\Social\Messages\Models\Message;

final readonly class PossibleDuplicate
{
    public function __construct(
        public Message $message,
        public DuplicateMatchEnum $match,
        public float $score,
    ) {
    }

    /**
     * `post_id` is null while the other message is still being published.
     */
    public function toArray(): array
    {
        $postId = (int) $this->message->get(CustomFieldEnum::POST_ID->value);

        return [
            'message_id' => $this->message->getId(),
            'post_id' => $postId > 0 ? $postId : null,
            'link' => $this->message->get(CustomFieldEnum::POST_URL->value),
            'match' => $this->match->value,
            'score' => round($this->score, 4),
        ];
    }
}
