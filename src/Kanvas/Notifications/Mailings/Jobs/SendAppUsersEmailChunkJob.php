<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Mailings\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Mailings\Models\AppUserMailing;
use Kanvas\Notifications\Mailings\Services\AppUserMailingNotificationFactory;
use Kanvas\Notifications\Mailings\Services\SentRecipientsLedger;
use Kanvas\Users\Models\Users;
use Throwable;

class SendAppUsersEmailChunkJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public int $timeout = 900;
    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(
        public readonly Apps $app,
        public readonly AppUserMailing $mailing,
        public readonly array $userIds,
    ) {
        $this->onQueue(SendAppUsersEmailJob::QUEUE);
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        $ledger = new SentRecipientsLedger($this->mailing);
        $pendingIds = array_values(array_diff($this->userIds, $ledger->sentAmong($this->userIds)));

        $factory = new AppUserMailingNotificationFactory(
            $this->app,
            $this->mailing->company,
            $this->mailing->requester,
        );
        $notification = $factory->make(
            $this->mailing->template_name,
            $this->mailing->subject,
            honorMarketingPreferences: true,
        );

        $firstError = null;

        foreach (Users::query()->whereIn('id', $pendingIds)->get() as $recipient) {
            try {
                Notification::sendNow($recipient, $factory->forRecipient($notification, $recipient));
                $ledger->markSent($recipient->getId());
            } catch (Throwable $e) {
                Log::warning('App user mailing could not reach a recipient', [
                    'mailing_id' => $this->mailing->getId(),
                    'users_id' => $recipient->getId(),
                    'error' => $e->getMessage(),
                ]);
                $firstError ??= $e;
            }
        }

        if ($firstError !== null) {
            report($firstError);
        }

        $this->recordOutcome($ledger);
    }

    public function failed(Throwable $exception): void
    {
        $this->recordOutcome(new SentRecipientsLedger($this->mailing), $exception->getMessage());
    }

    private function recordOutcome(SentRecipientsLedger $ledger, ?string $failureReason = null): void
    {
        $sent = count($ledger->sentAmong($this->userIds));

        $this->mailing->recordChunkResult($sent, count($this->userIds) - $sent, $failureReason);
    }
}
