<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

use Baka\Contracts\AppInterface;
use Kanvas\Intelligence\Enums\ConfigurationEnum;

/**
 * Resolves where the external voice runtime lives and how to authenticate to it.
 *
 * One Cloud Run deployment serves every app, so the URL + token come from the
 * global Kanvas config (env-backed: VOICE_RUNTIME_URL / VOICE_RUNTIME_API_TOKEN).
 * A per-app setting (kanvas-intelligence-voice-runtime-url / -api-token) still
 * wins when present, so a single app can be pointed at a different runtime
 * without touching the deployment.
 */
final class VoiceRuntimeConfig
{
    public static function url(?AppInterface $app = null): string
    {
        return self::resolve($app, ConfigurationEnum::VOICE_RUNTIME_URL->value, 'kanvas.voice_runtime.url');
    }

    public static function apiToken(?AppInterface $app = null): string
    {
        return self::resolve($app, ConfigurationEnum::VOICE_RUNTIME_API_TOKEN->value, 'kanvas.voice_runtime.api_token');
    }

    private static function resolve(?AppInterface $app, string $settingKey, string $configKey): string
    {
        $perApp = $app !== null ? trim((string) $app->get($settingKey)) : '';
        if ($perApp !== '') {
            return $perApp;
        }

        return trim((string) config($configKey, ''));
    }
}
