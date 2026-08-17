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
        $normalized = self::normalizePhoneNumber($phoneNumber);
        if ($normalized === '') {
            return null;
        }

        $crossApp = filter_var(
            $app->get(ConfigurationEnum::VOICE_RUNTIME_CROSS_APP->value),
            FILTER_VALIDATE_BOOLEAN
        );

        // Match on a digits-only form of BOTH sides so a stored number carrying
        // spaces / dashes / parens / a leading + still matches Twilio's strict
        // E.164 `To`. JSON_VALID guards rows whose voice_config is null or not
        // JSON. NOTE: this REPLACE/JSON chain can't use an index — fine at
        // per-app agent counts; if it ever gets hot, normalize on write and
        // index a generated column instead.
        $digitsOnly = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE("
            . "JSON_UNQUOTE(JSON_EXTRACT(voice_config, '$.phone_number')),"
            . " ' ', ''), '-', ''), '(', ''), ')', ''), '.', ''), '+', '')";

        $query = Agent::query()
            ->notDeleted()
            ->whereRaw('JSON_VALID(voice_config)')
            ->whereRaw("{$digitsOnly} = ?", [$normalized])
            ->orderBy('id');

        if (! $crossApp) {
            $query->where('apps_id', $app->getId());
        }

        return $query->first();
    }

    /**
     * Canonicalize a phone number for matching: digits only. Drops '+', spaces,
     * dashes, dots, parentheses, etc. so any human-entered format lines up with
     * Twilio's E.164 (e.g. "+1 (555) 123-4567" and "+15551234567" both become
     * "15551234567").
     */
    public static function normalizePhoneNumber(string $number): string
    {
        return preg_replace('/\D+/', '', $number) ?? '';
    }
}
