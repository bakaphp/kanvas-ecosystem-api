<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Mutations;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\PlaceVoiceTestCallAction;
use Kanvas\Intelligence\Agents\Models\Agent;

/**
 * User-guarded mutation (admin UI) that triggers a one-off TEST call for an
 * agent through the external voice runtime. Company-scoped: the agent must
 * belong to the caller's current company.
 */
class VoiceAgentCallMutation
{
    /**
     * @param array{id: int|string, to_number: string} $args
     *
     * @return array<string, mixed>
     */
    public function placeTestCall(mixed $root, array $args): array
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $agent = Agent::getByIdFromCompanyApp((int) $args['id'], $company, $app);

        return new PlaceVoiceTestCallAction($agent, $app, $company, (string) $args['to_number'])->execute();
    }
}
