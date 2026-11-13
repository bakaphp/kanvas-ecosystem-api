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
    private const string INBOUND_PATH = '/twilio/incoming';

    public static function url(): string
    {
        return rtrim(trim((string) config('kanvas.voice_runtime.url', '')), '/');
    }

    public static function inboundWebhookUrl(): string
    {
        $url = self::url();

        return $url !== '' ? $url . self::INBOUND_PATH : '';
    }

    /**
     * Whether a webhook is some voice runtime's inbound route, this environment's or another's
     * (the prod service, a dev one, an old URL). A number left on one of those is ours to move.
     */
    public static function isRuntimeInboundUrl(string $url): bool
    {
        return str_ends_with(rtrim((string) parse_url($url, PHP_URL_PATH), '/'), self::INBOUND_PATH);
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
