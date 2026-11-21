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

    public const string SETTLED = 'settled';
    public const string RETRY = 'retry';

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
     * The marker lasts a day only once the row is saved and its alert delivered. Anything short of
     * that leaves a short-lived one, so a failed write or a failed alert is tried again in minutes
     * rather than hidden for a day.
     */
    public function execute(): void
    {
        try {
            if (Cache::has($this->markerKey())) {
                return;
            }

            $settled = $this->record();
        } catch (Throwable $e) {
            report($e);
            $settled = false;
        }

        try {
            Cache::put(
                $this->markerKey(),
                $settled ? self::SETTLED : self::RETRY,
                $settled ? self::SEEN_TTL_SECONDS : self::RETRY_TTL_SECONDS
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function markerKey(): string
    {
        return 'app-key-source:' . $this->appKey->client_id . ':' . sha1($this->ip);
    }

    private function record(): bool
    {
        $seen = [
            'user_agent' => $this->userAgent(),
            'last_seen_at' => now(),
        ];
        // null on a key instance built before the column was read back, which the column refuses
        $watched = (bool) $this->appKey->alert_on_new_source;

        $source = AppKeySource::firstOrCreate(
            [
                'client_id' => $this->appKey->client_id,
                'ip' => $this->ip,
            ],
            [
                ...$seen,
                'apps_id' => $this->appKey->apps_id,
                'first_seen_at' => $seen['last_seen_at'],
                'sentry_alert_pending' => $watched,
                'email_alert_pending' => $watched,
            ]
        );

        if (! $source->wasRecentlyCreated) {
            $source->update($seen);
        }

        if (! $source->hasPendingAlert() || ! $watched) {
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
