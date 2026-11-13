<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Queries\Agent;

use Kanvas\Intelligence\Agents\Services\VoiceRuntimeConfig;

class VoiceAgentDefaultsQuery
{
    /**
     * @param array<string, mixed> $args
     *
     * @return array{voice_id: string|null}
     */
    public function __invoke(mixed $root, array $args): array
    {
        return ['voice_id' => VoiceRuntimeConfig::defaultVoiceId()];
    }
}
