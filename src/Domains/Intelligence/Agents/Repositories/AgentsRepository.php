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

        // Narrow to voice-configured agents in SQL (a small set — only agents
        // with a voice_config), then match in PHP with the SAME
        // normalizePhoneNumber on both sides. One canonical, unit-testable
        // normalizer, no raw/DB-specific SQL, and no risk of a SQL form drifting
        // from the PHP one. Fine at per-agent counts; if the voice-agent set ever
        // grows large, normalize on write and index the number instead.
        $query = Agent::query()
            ->notDeleted()
            ->whereNotNull('voice_config')
            ->orderBy('id');

        if (! $crossApp) {
            $query->where('apps_id', $app->getId());
        }

        return $query->get()->first(function (Agent $agent) use ($normalized): bool {
            $stored = $agent->voice_config['phone_number'] ?? null;

            return $stored !== null
                && self::normalizePhoneNumber((string) $stored) === $normalized;
        });
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

    /**
     * Canonicalize a phone number for STORAGE: strip spaces, dashes, parens,
     * etc. but PRESERVE a leading + so it stays a valid E.164 Twilio caller id.
     * Unlike normalizePhoneNumber (digits only, for lenient matching), this keeps
     * the +. e.g. "+1 (555) 123-4567" -> "+15551234567", "5551234567" -> "5551234567".
     */
    public static function cleanPhoneNumber(string $number): string
    {
        $number = trim($number);
        $digits = preg_replace('/\D+/', '', $number) ?? '';

        if ($digits === '') {
            return '';
        }

        return str_starts_with($number, '+') ? '+' . $digits : $digits;
    }
}
