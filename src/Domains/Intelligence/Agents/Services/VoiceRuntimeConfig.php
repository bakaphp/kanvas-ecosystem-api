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

    public static function apiToken(): string
    {
        return trim((string) config('kanvas.voice_runtime.api_token', ''));
    }

    public static function isConfigured(): bool
    {
        return self::url() !== '' && self::apiToken() !== '';
    }
}
