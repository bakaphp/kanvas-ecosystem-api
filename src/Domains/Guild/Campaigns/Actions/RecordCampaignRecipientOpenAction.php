<?php

declare(strict_types=1);

namespace Kanvas\Guild\Campaigns\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Guild\Campaigns\Enums\CampaignRecipientStatusEnum;
use Kanvas\Guild\Campaigns\Models\Campaign;
use Kanvas\Guild\Campaigns\Models\CampaignRecipient;

/**
 * Only the first open flips the slot to `opened` and counts toward the campaign's `opened_count`;
 * later opens just bump `open_count`. The first-open claim is a conditional UPDATE (`opened_at IS
 * NULL`) rather than read-then-write, so two opens delivered concurrently can't both count as first.
 */
class RecordCampaignRecipientOpenAction
{
    public function __construct(
        private readonly CampaignRecipient $recipient,
    ) {
    }

    /**
     * @return bool whether this was the recipient's first open
     */
    public function execute(): bool
    {
        return DB::connection('crm')->transaction(function (): bool {
            $firstOpen = CampaignRecipient::query()
                ->whereKey($this->recipient->getId())
                ->whereNull('opened_at')
                ->update([
                    'status' => CampaignRecipientStatusEnum::OPENED->value,
                    'opened_at' => Carbon::now(),
                    'open_count' => DB::raw('open_count + 1'),
                ]) === 1;

            if ($firstOpen) {
                Campaign::query()->whereKey($this->recipient->lead_campaigns_id)->increment('opened_count');
            } else {
                CampaignRecipient::query()->whereKey($this->recipient->getId())->increment('open_count');
            }

            $this->recipient->refresh();

            return $firstOpen;
        });
    }
}
