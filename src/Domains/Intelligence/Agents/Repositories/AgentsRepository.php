<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Repositories;

use Baka\Contracts\AppInterface;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\ConfigurationEnum;

class AgentsRepository
{
    /**
     * Resolve a single agent by uuid for the voice runtime's spec fetch
     * (server-to-server via app key).
     *
     * By default this is app-scoped: an app's credentials can only resolve its
     * own agents. The voice runtime, however, is a single trusted service that
     * may serve agents living in DIFFERENT apps. To allow that WITHOUT turning
     * every app-key into a cross-tenant reader, the calling app must be
     * explicitly flagged via the VOICE_RUNTIME_CROSS_APP setting — only then do
     * we resolve by uuid across apps. Any other app stays strictly app-scoped.
     *
     * Throws ModelNotFoundException when the uuid does not resolve under the
     * effective scope.
     */
    public static function getByUuidForVoiceRuntime(string $uuid, AppInterface $app): Agent
    {
        $crossApp = filter_var(
            $app->get(ConfigurationEnum::VOICE_RUNTIME_CROSS_APP->value),
            FILTER_VALIDATE_BOOLEAN
        );

        $query = Agent::where('uuid', $uuid);

        if (! $crossApp) {
            $query->where('apps_id', $app->getId());
        }

        return $query->firstOrFail();
    }

    /**
     * Resolve the agent that owns a given inbound phone number (the per-agent
     * voice_config.phone_number set in the admin UI), for the voice runtime's
     * inbound routing. Returns null when no agent claims the number so the
     * caller can fall back to a default agent instead of dropping the call.
     *
     * Same cross-app trust model as getByUuidForVoiceRuntime: app-scoped unless
     * the calling app is flagged VOICE_RUNTIME_CROSS_APP.
     */
    public static function getByPhoneForVoiceRuntime(string $phoneNumber, AppInterface $app): ?Agent
    {
        $crossApp = filter_var(
            $app->get(ConfigurationEnum::VOICE_RUNTIME_CROSS_APP->value),
            FILTER_VALIDATE_BOOLEAN
        );

        $query = Agent::where('voice_config->phone_number', $phoneNumber)
            ->notDeleted();

        if (! $crossApp) {
            $query->where('apps_id', $app->getId());
        }

        return $query->first();
    }
}
