<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

/**
 * Where the external voice runtime lives and how to authenticate to it.
 *
 * One Cloud Run deployment serves every app and company, so both come from the
 * environment only (VOICE_RUNTIME_URL / VOICE_RUNTIME_API_TOKEN), never from app
 * or company settings.
 */
final class VoiceRuntimeConfig
{
    public static function url(): string
    {
        return rtrim(trim((string) config('kanvas.voice_runtime.url', '')), '/');
    }

    public static function inboundWebhookUrl(): string
    {
        $url = self::url();

        return $url !== '' ? $url . '/twilio/incoming' : '';
    }

    /**
     * The ElevenLabs voice for agents that don't pick one, or null when the environment sets none
     * (the runtime then falls back to its own ELEVENLABS_VOICE_ID).
     */
    public static function defaultVoiceId(): ?string
    {
        $voiceId = trim((string) config('kanvas.voice_runtime.default_voice_id', ''));

        return $voiceId !== '' ? $voiceId : null;
    }

    public static function apiToken(): string
    {
        return trim((string) config('kanvas.voice_runtime.api_token', ''));
    }

    public static function isConfigured(): bool
    {
        return self::url() !== '' && self::apiToken() !== '';
    }

    /**
     * Whether a request carries the runtime's own token. The runtime sends back the token Kanvas
     * calls it with, so it proves the caller is the runtime and not just someone holding an app key.
     */
    public static function acceptsToken(?string $token): bool
    {
        $expected = self::apiToken();

        return $expected !== '' && $token !== null && hash_equals($expected, $token);
    }
}
