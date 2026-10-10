<?php

declare(strict_types=1);

namespace Kanvas\Guild\Campaigns\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Campaigns\Enums\CampaignStatusEnum;
use Kanvas\Guild\Campaigns\Models\Campaign;
use Kanvas\Guild\Campaigns\Models\CampaignRecipient;

/**
 * Appends more people-only recipients to a campaign that hasn't started sending yet. Only safe
 * while the campaign is still SCHEDULED with a future scheduled_at — once ProcessLeadCampaignJob
 * is dispatched (sending/sent/failed) it has already read its one-shot recipient list, so a late
 * insert would sit PENDING forever. The caller creates a new campaign for those people instead.
 */
class AddRecipientsToCampaignAction
{
    public function __construct(
        private readonly Campaign $campaign,
    ) {
    }

    public static function canAddTo(Campaign $campaign): bool
    {
        return $campaign->status === CampaignStatusEnum::SCHEDULED->value
            && $campaign->scheduled_at !== null
            && $campaign->scheduled_at->isFuture();
    }

    /**
     * @param  array<int, array<string, mixed>>  $eligibleRecipients  Resolver eligible rows (people_id).
     *
     * @return array{added: int, already_in_campaign: list<int>}
     */
    public function execute(array $eligibleRecipients): array
    {
        if (! self::canAddTo($this->campaign)) {
            throw new ValidationException(
                'This campaign is no longer scheduled (it is already sending, sent, or failed), so recipients '
                . 'can no longer be added to it. Create a new campaign for these people instead.'
            );
        }

        $existingPeopleIds = $this->campaign->recipients()
            ->whereNotNull('peoples_id')
            ->pluck('peoples_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $added = 0;
        $alreadyInCampaign = [];

        DB::connection('crm')->transaction(function () use ($eligibleRecipients, &$existingPeopleIds, &$added, &$alreadyInCampaign): void {
            foreach ($eligibleRecipients as $row) {
                $peopleId = (int) ($row['people_id'] ?? 0);

                if ($peopleId === 0) {
                    continue;
                }

                if (in_array($peopleId, $existingPeopleIds, true)) {
                    $alreadyInCampaign[] = $peopleId;

                    continue;
                }

                CampaignRecipient::pending($this->campaign, null, $peopleId)->saveOrFail();

                $existingPeopleIds[] = $peopleId;
                $added++;
            }

            if ($added > 0) {
                $this->campaign->total_recipients += $added;
                $this->campaign->saveOrFail();
            }
        });

        return [
            'added' => $added,
            'already_in_campaign' => $alreadyInCampaign,
        ];
    }
}
