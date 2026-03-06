<?php

declare(strict_types=1);

namespace Kanvas\Users\GraphQL\Queries;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Messages\Models\Message;

class UserUsageStatsQuery
{
    /**
     * Get usage stats for the current user.
     *
     * @param mixed $root
     * @param array $args
     * @return array
     */
    public function getUsageStats(mixed $root, array $args): array
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $days = 7;

        // Ensure we cover the last 7 days including today
        $startDate = Carbon::now()->subDays($days - 1)->startOfDay();
        $endDate = Carbon::now()->endOfDay();

        // Get the counts grouped by date
        $usageData = Message::fromApp($app)
            ->where('users_id', $user->getId())
            ->where('created_at', '>=', $startDate)
            ->whereNull('parent_id') // Count only root messages
            ->select([
                DB::raw('DATE(created_at) as date'),
                DB::raw('count(*) as count')
            ])
            ->groupBy('date')
            ->orderBy('date', 'ASC')
            ->toBase()
            ->get()
            ->keyBy('date');

        // Fill in missing dates with 0
        $stats = [];
        $currentDate = clone $startDate;

        while ($currentDate <= $endDate) {
            $dateStr = $currentDate->format('Y-m-d');
            $stats[] = [
                'date' => $dateStr,
                'count' => $usageData->has($dateStr) ? (int) $usageData[$dateStr]->count : 0,
            ];
            $currentDate->addDay();
        }

        return $stats;
    }
}
