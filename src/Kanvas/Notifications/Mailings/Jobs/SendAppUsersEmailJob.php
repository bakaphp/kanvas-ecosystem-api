<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Mailings\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Mailings\Enums\AppUserMailingStatusEnum;
use Kanvas\Notifications\Mailings\Models\AppUserMailing;
use Kanvas\Notifications\Mailings\Services\AppUserMailingEligibilityService;
use Throwable;

class SendAppUsersEmailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public const string QUEUE = 'app-mailing';
    public const int CHUNK_SIZE = 100;

    public int $timeout = 3600;
    public int $tries = 1;

    public function __construct(
        public readonly Apps $app,
        public readonly AppUserMailing $mailing,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        if ($this->mailing->status !== AppUserMailingStatusEnum::QUEUED) {
            return;
        }

        $recipientIds = array_values($this->mailing->recipient_ids ?? []);

        if ($this->mailing->scheduled_at !== null) {
            $recipientIds = $this->dropRecipientsNoLongerEligible($recipientIds);
        }

        $this->mailing->status = AppUserMailingStatusEnum::SENDING;
        $this->mailing->started_at = Carbon::now();

        if ($recipientIds === []) {
            $this->mailing->status = AppUserMailingStatusEnum::COMPLETED;
            $this->mailing->completed_at = Carbon::now();
        }

        $this->mailing->saveOrFail();

        foreach (array_chunk($recipientIds, self::CHUNK_SIZE) as $userIds) {
            SendAppUsersEmailChunkJob::dispatch($this->app, $this->mailing, $userIds);
        }
    }

    private function dropRecipientsNoLongerEligible(array $recipientIds): array
    {
        $partition = new AppUserMailingEligibilityService($this->app)->partition($recipientIds);

        $this->mailing->applyDeliveryEligibility($partition['eligible'], $partition['excluded']);

        return $this->mailing->recipient_ids;
    }

    public function failed(Throwable $exception): void
    {
        $this->mailing->markFailed($exception->getMessage());
    }
}
