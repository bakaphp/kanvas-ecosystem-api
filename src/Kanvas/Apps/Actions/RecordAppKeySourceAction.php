<?php

declare(strict_types=1);

namespace Kanvas\Apps\Actions;

use Baka\Support\Str;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\AppKey;
use Kanvas\Apps\Models\AppKeySource;
use Throwable;

class RecordAppKeySourceAction
{
    private const int SEEN_TTL_SECONDS = 86400;
    private const int RETRY_TTL_SECONDS = 300;
    private const string UNKNOWN_IP = 'unknown';

    private readonly string $ip;

    /**
     * A caller can send a forwarded header that resolves to nothing; that still has to be a source
     * on file, or an empty value would be a way to use the key unrecorded.
     */
    public function __construct(
        private readonly AppKey $appKey,
        string $ip,
        private readonly ?string $userAgent = null,
    ) {
        $this->ip = Str::trimToNull($ip) ?? self::UNKNOWN_IP;
    }

    /**
     * Runs on every request that carries an app key, so it must stay one cache read for a known
     * source and must never fail the request it is observing.
     *
     * The day-long marker is written only once the row is saved and its alert delivered. Anything
     * short of that leaves the short retry marker instead, so a failed write or a failed alert is
     * tried again in minutes rather than hidden for a day.
     */
    public function execute(): void
    {
        try {
            if (Cache::has($this->seenKey()) || Cache::has($this->retryKey())) {
                return;
            }

            $settled = $this->record();
        } catch (Throwable $e) {
            report($e);
            $settled = false;
        }

        try {
            $settled
                ? Cache::put($this->seenKey(), 1, self::SEEN_TTL_SECONDS)
                : Cache::put($this->retryKey(), 1, self::RETRY_TTL_SECONDS);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function seenKey(): string
    {
        return 'app-key-source:' . $this->appKey->client_id . ':' . sha1($this->ip);
    }

    public function retryKey(): string
    {
        return $this->seenKey() . ':retry';
    }

    private function record(): bool
    {
        $source = AppKeySource::firstOrCreate(
            [
                'client_id' => $this->appKey->client_id,
                'ip' => $this->ip,
            ],
            [
                'apps_id' => $this->appKey->apps_id,
                'user_agent' => $this->userAgent(),
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'sentry_alert_pending' => (bool) $this->appKey->alert_on_new_source,
                'email_alert_pending' => (bool) $this->appKey->alert_on_new_source,
            ]
        );

        if (! $source->wasRecentlyCreated) {
            $source->update([
                'user_agent' => $this->userAgent(),
                'last_seen_at' => now(),
            ]);
        }

        if (! $source->hasPendingAlert() || ! $this->appKey->alert_on_new_source) {
            return true;
        }

        return new AlertAppKeyNewSourceAction($this->appKey, $source)->execute();
    }

    private function userAgent(): ?string
    {
        $userAgent = Str::trimToNull($this->userAgent);

        return $userAgent === null ? null : mb_substr($userAgent, 0, 255);
    }
}
