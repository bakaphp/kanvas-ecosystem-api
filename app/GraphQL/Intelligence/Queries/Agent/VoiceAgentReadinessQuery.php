<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Queries\Agent;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Intelligence\Agents\Actions\Voice\CheckVoiceAgentReadinessAction;
use Kanvas\Intelligence\Agents\Models\Agent;

class VoiceAgentReadinessQuery
{
    use ResolvesActingContext;

    /**
     * @param array{id: int|string} $args
     *
     * @return array<string, mixed>
     */
    public function __invoke(mixed $root, array $args): array
    {
        $ctx = $this->actingContext();
        $agent = Agent::getByIdFromCompanyApp((int) $args['id'], $ctx->company, $ctx->app);

        return new CheckVoiceAgentReadinessAction($agent)->execute();
    }
}
