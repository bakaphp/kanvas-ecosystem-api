<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Sessions\DataTransferObject;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\Data;

class Session extends Data
{
    public function __construct(
        public Apps $app,
        public Companies $company,
        public Channel $channel,
        public Agent $agent,
        public string $entity_namespace,
        public string $entity_id,
        public array $user,
        public ?string $canal_id = null,
        public ?int $communication_channels_id = null,
        public array $content = [],
        public ?Users $userModel = null,
    ) {
    }
}
