<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;

/**
 * A new app has no LLM key of its own, so for its first days its Gemini agents run on the platform
 * key from .env, on the trial model whatever the agent asks for: the platform pays, so the platform
 * picks the price. AGENT_TRIAL_LLM_DAYS=0 switches it off; after the window the usual "key is not
 * configured" error is what the person sees.
 */
final class TrialLlmService
{
    public static function geminiKeyFor(Apps $app): ?string
    {
        $endsAt = self::trialEndsAt($app);
        if ($endsAt === null || now()->greaterThanOrEqualTo($endsAt)) {
            return null;
        }

        $key = Str::trimToNull((string) config('ai.providers.gemini.key'));
        if ($key === null) {
            return null;
        }

        if (Cache::add('agents:trial-llm:' . $app->getId(), true, now()->addDay())) {
            Log::info('App is running agents on the platform trial LLM key', [
                'apps_id' => $app->getId(),
                'trial_ends_at' => $endsAt->toDateString(),
            ]);
        }

        return $key;
    }

    public static function model(): string
    {
        return (string) config('kanvas.agents.trial_llm_model');
    }

    private static function trialEndsAt(Apps $app): ?Carbon
    {
        $days = (int) config('kanvas.agents.trial_llm_days');
        if ($days <= 0 || empty($app->created_at)) {
            return null;
        }

        return Carbon::parse((string) $app->created_at)->addDays($days);
    }
}
