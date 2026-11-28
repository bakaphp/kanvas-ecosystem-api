<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Mailings\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Models\BaseModel;
use Kanvas\Notifications\Mailings\Enums\AppUserMailingAudienceEnum;
use Kanvas\Notifications\Mailings\Enums\AppUserMailingStatusEnum;
use Kanvas\Users\Models\Users;
use Override;

class AppUserMailing extends BaseModel
{
    use UuidTrait;

    protected $table = 'app_user_mailings';
    protected $guarded = ['id'];
    protected $hidden = ['recipient_ids'];

    #[Override]
    protected function casts(): array
    {
        return [
            'audience' => AppUserMailingAudienceEnum::class,
            'criteria' => Json::class,
            'excluded_breakdown' => Json::class,
            'recipient_ids' => Json::class,
            'status' => AppUserMailingStatusEnum::class,
            'confirmed_total' => 'integer',
            'total_eligible' => 'integer',
            'total_excluded' => 'integer',
            'sent_count' => 'integer',
            'failed_count' => 'integer',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'is_deleted' => 'boolean',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id');
    }

    public function statusValue(): string
    {
        return $this->status->value;
    }

    public function audienceValue(): string
    {
        return $this->audience->value;
    }

    public function recordChunkResult(int $sent, int $failed, ?string $failureReason = null): void
    {
        static::query()->whereKey($this->getKey())->incrementEach([
            'sent_count' => $sent,
            'failed_count' => $failed,
        ]);

        $closedStatus = sprintf(
            "CASE WHEN sent_count = 0 THEN '%s' ELSE '%s' END",
            AppUserMailingStatusEnum::FAILED->value,
            AppUserMailingStatusEnum::COMPLETED->value,
        );

        static::query()
            ->whereKey($this->getKey())
            ->where('status', AppUserMailingStatusEnum::SENDING->value)
            ->whereRaw('sent_count + failed_count >= total_eligible')
            ->update([
                'status' => DB::raw($closedStatus),
                'completed_at' => Carbon::now(),
            ]);

        if ($failureReason !== null) {
            static::query()
                ->whereKey($this->getKey())
                ->whereNull('failed_reason')
                ->update(['failed_reason' => $failureReason]);
        }

        $this->refresh();
    }

    public function claimForDispatch(): bool
    {
        $claimed = static::query()
            ->whereKey($this->getKey())
            ->where('status', AppUserMailingStatusEnum::SCHEDULED->value)
            ->update(['status' => AppUserMailingStatusEnum::QUEUED->value]) === 1;

        if ($claimed) {
            $this->refresh();
        }

        return $claimed;
    }

    public function cancelIfScheduled(): bool
    {
        $cancelled = static::query()
            ->whereKey($this->getKey())
            ->where('status', AppUserMailingStatusEnum::SCHEDULED->value)
            ->update([
                'status' => AppUserMailingStatusEnum::CANCELLED->value,
                'completed_at' => Carbon::now(),
            ]) === 1;

        $this->refresh();

        return $cancelled;
    }

    public function applyDeliveryEligibility(array $eligibleIds, array $excludedNow): void
    {
        $breakdown = $this->excluded_breakdown ?? [];

        foreach ($excludedNow as $reason => $count) {
            $breakdown[$reason] = ($breakdown[$reason] ?? 0) + $count;
        }

        $this->recipient_ids = array_values($eligibleIds);
        $this->total_eligible = count($eligibleIds);
        $this->total_excluded += array_sum($excludedNow);
        $this->excluded_breakdown = $breakdown;
    }

    public function markFailed(string $reason): void
    {
        static::query()
            ->whereKey($this->getKey())
            ->whereIn('status', [AppUserMailingStatusEnum::QUEUED->value, AppUserMailingStatusEnum::SENDING->value])
            ->update([
                'status' => AppUserMailingStatusEnum::FAILED->value,
                'failed_reason' => $reason,
                'completed_at' => Carbon::now(),
            ]);

        $this->refresh();
    }
}
