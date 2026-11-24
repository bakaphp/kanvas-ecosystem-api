<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Kanvas\Approvals\Enums\ApprovalDecisionEnum;
use Kanvas\Approvals\Enums\ApprovalNotifyModeEnum;
use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Models\ApprovalRequestApprover;
use Kanvas\Approvals\Notifications\ApprovalDigestNotification;
use Kanvas\Approvals\Notifications\ApprovalRequestedNotification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;

class SendApprovalDigestJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public const int WINDOW_MINUTES = 5;

    public function __construct(
        public readonly Apps $app,
        public readonly Companies $company,
        public readonly Users $user,
    ) {
        $this->onQueue('notifications');
    }

    /**
     * The first request opened for this approver starts the window and the rest ride on it. The key is
     * released when the job starts, not when it ends, so a request opened while the digest is being
     * built starts the next window instead of being left un-notified.
     */
    public static function schedule(Apps $app, Companies $company, Users $user): void
    {
        $opened = Cache::add(
            self::windowKey($app, $company, $user),
            true,
            Carbon::now()->addMinutes(self::WINDOW_MINUTES * 2)
        );

        if (! $opened) {
            return;
        }

        self::dispatch($app, $company, $user)->delay(Carbon::now()->addMinutes(self::WINDOW_MINUTES));
    }

    public static function windowKey(Apps $app, Companies $company, Users $user): string
    {
        return sprintf('approval-digest:%d:%d:%d', $app->getId(), $company->getId(), $user->getId());
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);
        Cache::forget(self::windowKey($this->app, $this->company, $this->user));

        $rows = ApprovalRequestApprover::query()
            ->where('users_id', $this->user->getId())
            ->where('decision', ApprovalDecisionEnum::PENDING)
            ->whereNull('notified_at')
            ->where('is_deleted', false)
            ->whereHas(
                'request',
                fn ($query) => $query
                    ->where('apps_id', $this->app->getId())
                    ->where('companies_id', $this->company->getId())
                    ->where('status', ApprovalStatusEnum::PENDING)
                    ->whereColumn('approval_requests.current_step', 'approval_request_approvers.step')
                    ->whereHas(
                        'policy',
                        fn ($policy) => $policy->where('notify', ApprovalNotifyModeEnum::DIGEST->value)
                    )
            )
            ->with('request')
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $this->user->notify(
            $rows->count() === 1
                ? new ApprovalRequestedNotification($rows->first()->request)
                : new ApprovalDigestNotification($this->company, $rows->pluck('request'))
        );

        $rows->each(fn (ApprovalRequestApprover $row) => $row->markNotified());
    }
}
