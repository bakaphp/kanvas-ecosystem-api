<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\OpenClaw\Queries;

use Illuminate\Support\Facades\Cache;

class AgentTelemetryQuery
{
    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|null
     */
    public function __invoke(mixed $root, array $args): ?array
    {
        /** @var array<string, mixed>|null */
        return Cache::get('openclaw:telemetry:' . $args['deployment_id']);
    }
}
