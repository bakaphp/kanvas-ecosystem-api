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
 * The mapping is the #1 push risk: an unknown assignee is SILENTLY dropped by the Hermes
 * dispatcher (the task never runs). Source of truth is the agent's HERMES_KANBAN_PROFILE
 * custom field (captured at launch); we fall back to the agent slug so existing agents still
 * resolve. Callers should still validate the resolved name against `kanban assignees --json`
 * before pushing — see the listed assignees on the runner.
 */
final class HermesProfileResolver
{
    public static function forAgent(Agent $agent): string
    {
        $profile = (string) ($agent->get(CustomFieldEnum::HERMES_KANBAN_PROFILE->value) ?? '');

        return $profile !== '' ? $profile : $agent->slug;
    }

    // Reverse map for ingest: which Kanvas agent owns this Hermes profile. Matches on the
    // explicit profile custom field first, then the slug fallback that forAgent() mirrors.
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
