<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Kanban\Support;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Hermes\Kanban\Enums\CustomFieldEnum;
use Kanvas\Intelligence\Agents\Models\Agent;

/**
 * Maps a Kanvas Agent ↔ its Hermes kanban `assignee` (profile name).
 *
 * The mapping is the #1 push risk: an unknown assignee is SILENTLY dropped by the dispatcher (the
 * card is created but never spawned). In the Docker-isolation model each agent has its own container
 * whose single profile is `default`, so that's the fallback — NOT the agent slug (a slug like
 * `hermes-agent-agent` matches no profile and the card just sits). Override per agent with the
 * HERMES_KANBAN_PROFILE custom field for hosts that run named/multiple profiles.
 */
final class HermesProfileResolver
{
    public const DEFAULT_PROFILE = 'default';

    public static function forAgent(Agent $agent): string
    {
        $profile = (string) ($agent->get(CustomFieldEnum::HERMES_KANBAN_PROFILE->value) ?? '');

        return $profile !== '' ? $profile : self::DEFAULT_PROFILE;
    }

    // Reverse map for ingest: which Kanvas agent owns this Hermes profile. Matches the explicit
    // profile custom field, then the slug. (The default-profile fallback isn't reversible — a board
    // is sliced by the deployment's agent anyway, so ingest already knows the agent.)
    public static function toAgent(string $profile, AppInterface $app, CompanyInterface $company): ?Agent
    {
        if ($profile === '') {
            return null;
        }

        /** @var Agent|null $agent */
        $agent = Agent::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->where('slug', $profile)
            ->first();

        return $agent;
    }
}
