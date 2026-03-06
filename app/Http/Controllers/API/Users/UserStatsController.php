<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Users;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Messages\Models\Message;

class UserStatsController extends Controller
{
    /**
     * Get usage stats for the current user.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $days = 7;

        // Ensure we cover the last 7 days including today
        $startDate = Carbon::now()->subDays($days - 1)->startOfDay();
        $endDate = Carbon::now()->endOfDay();

        // Get the counts grouped by date
        // We use the social connection explicitly for the raw statement if needed, 
        // but Eloquent handles the connection for the model.
        $usageData = Message::fromApp($app)
            ->where('users_id', $user->getId())
            ->where('created_at', '>=', $startDate)
            ->whereNull('parent_id') // Count only root messages (prompts/interactions)
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

        return response()->json([
            'data' => $stats
        ]);
    }
}
