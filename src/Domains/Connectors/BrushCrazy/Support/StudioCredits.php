<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Support;

use Kanvas\Connectors\BrushCrazy\Client;
use stdClass;

class StudioCredits
{
    public function __construct(
        protected Client $client,
    ) {
    }

    /**
     * Summed here rather than in SQL because `amount` is BrushCrazy's Money JSON. Trashed rows are
     * left out the same way the source's `credits()->forStudio()` leaves them out when charging.
     * A user with no credit row in the studio is absent from the result.
     *
     * @param  array<int, int>  $bcUserIds
     * @return array<int, int> bc user id => cents
     */
    public function forUsers(int $bcStudioId, array $bcUserIds): array
    {
        if ($bcUserIds === []) {
            return [];
        }

        $totals = [];

        $this->client->table('customer_credits')
            ->select(['user_id', 'amount'])
            ->where('studio_id', $bcStudioId)
            ->whereIn('user_id', $bcUserIds)
            ->whereNull('deleted_at')
            ->get()
            ->each(function (stdClass $credit) use (&$totals) {
                $userId = (int) $credit->user_id;
                $totals[$userId] = ($totals[$userId] ?? 0) + Money::fromRaw($credit->amount)->cents;
            });

        return $totals;
    }
}
