<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Sessions\DataTransferObject;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Spatie\LaravelData\Data;
use Kanvas\Social\Channels\Models\Channel;
class Session extends Data
{
    public function __construct(
        public Apps $app,
        public Companies $company,
        public Agent $agent,
        public Channel $channel,
        public string $entity_namespace,
        public string $entity_id,
        public string $session_id,
        public string $content,
        public ?string $canal_id = null,
        public ?int $communication_channels_id = null,
    ) {
    }
}
