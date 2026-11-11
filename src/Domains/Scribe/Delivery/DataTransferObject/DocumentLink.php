<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Delivery\DataTransferObject;

use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Scribe\Delivery\Enums\DocumentActionEnum;

final class DocumentLink
{
    public function __construct(
        public readonly Engagement $engagement,
        public readonly string $link,
        public readonly People $people,
        public readonly DocumentActionEnum $action,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toToolResult(): array
    {
        return [
            'person_id' => $this->people->getId(),
            'person' => $this->people->getName(),
            'link' => $this->link,
            'engagement_uuid' => $this->engagement->uuid,
            'action' => $this->action->value,
        ];
    }
}
