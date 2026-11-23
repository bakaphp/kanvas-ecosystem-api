<?php

declare(strict_types=1);

namespace Kanvas\Apps\Actions;

use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\AppKey;
use Kanvas\Apps\Models\AppKeySource;
use Kanvas\Apps\Notifications\AppKeyNewSourceNotification;
use Sentry\SentrySdk;
use Sentry\Severity;
use Sentry\State\Scope;
use Throwable;

use function Sentry\captureMessage;
use function Sentry\withScope;

/**
 * Each channel is owed separately and cleared only on its own success, so a retry never repeats
 * the one that already went out.
 */
class AlertAppKeyNewSourceAction
{
    public function __construct(
        private readonly AppKey $appKey,
        private readonly AppKeySource $source,
    ) {
    }

    /**
     * @return bool whether nothing is owed any more
     */
    public function execute(): bool
    {
        if ($this->source->sentry_alert_pending && $this->attempt($this->alertSentry(...))) {
            $this->source->update(['sentry_alert_pending' => false]);
        }

        if ($this->source->email_alert_pending && $this->attempt($this->alertPlatformOwners(...))) {
            $this->source->update(['email_alert_pending' => false]);
        }

        return ! $this->source->hasPendingAlert();
    }

    /**
     * @param callable(): bool $channel
     */
    private function attempt(callable $channel): bool
    {
        try {
            return $channel();
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Queued, so success here is the hand-off to the queue; the mail itself retries there.
     */
    private function alertPlatformOwners(): bool
    {
        $recipients = Str::emailList(config('kanvas.capability_gap.alert_emails', ''));

        if ($recipients === []) {
            return true;
        }

        Notification::route('mail', $recipients)->notify(
            new AppKeyNewSourceNotification(
                keyName: (string) $this->appKey->name,
                clientId: $this->appKey->client_id,
                appId: (int) $this->appKey->apps_id,
                appName: (string) $this->appKey->app?->name,
                ip: $this->source->ip,
                userAgent: $this->source->user_agent,
                seenAt: Carbon::parse($this->source->first_seen_at)->utc()->toDateTimeString(),
            )
        );

        return true;
    }

    /**
     * The SDK swallows a failed send and answers with no event id, so the id is the only proof of
     * delivery. An environment with no DSN has nowhere to send and owes nothing.
     */
    private function alertSentry(): bool
    {
        if (SentrySdk::getCurrentHub()->getClient()?->getOptions()->getDsn() === null) {
            return true;
        }

        $eventId = null;

        withScope(function (Scope $scope) use (&$eventId): void {
            $scope->setLevel(Severity::warning());
            $scope->setTag('app_id', (string) $this->appKey->apps_id);
            $scope->setTag('app_key', (string) $this->appKey->name);
            $scope->setContext('app_key_source', [
                'client_id' => $this->appKey->client_id,
                'ip' => $this->source->ip,
                'user_agent' => $this->source->user_agent,
            ]);
            $scope->setFingerprint(['app-key-new-source', $this->appKey->client_id]);

            $eventId = captureMessage(
                sprintf('App key "%s" used from a new source: %s', $this->appKey->name, $this->source->ip),
                Severity::warning()
            );
        });

        return $eventId !== null;
    }
}
