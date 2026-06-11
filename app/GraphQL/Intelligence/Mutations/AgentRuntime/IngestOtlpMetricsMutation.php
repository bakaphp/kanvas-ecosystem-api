<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Mutations\AgentRuntime;

use Kanvas\Intelligence\AgentRuntime\Services\OtlpUsageIngestionService;

class IngestOtlpMetricsMutation
{
    public function __invoke(mixed $root, array $args): bool
    {
        /** @var array<string, mixed> $payload */
        $payload = $args['payload'] ?? [];

        if (empty($payload)) {
            return true;
        }

        return app(OtlpUsageIngestionService::class)->ingest($payload);
    }
}
