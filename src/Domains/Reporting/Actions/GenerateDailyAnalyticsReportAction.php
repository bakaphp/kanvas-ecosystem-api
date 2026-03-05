<?php

declare(strict_types=1);

namespace Kanvas\Reporting\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Amplitude\Client;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class GenerateDailyAnalyticsReportAction
{
    protected array $events;

    public function __construct(array $events)
    {
        $this->events = $events;
    }

    public function execute(): array
    {
        // Initialize Metrics
        $metrics = [
            'date' => Carbon::yesterday()->format('Y-m-d'),
            'funnel' => [
                'explore' => ['views' => 0, 'select' => 0, 'try' => 0, 'edit' => 0, 'generate' => 0],
                'composer' => ['views' => 0, 'select' => 0, 'prompt' => 0, 'generate' => 0],
            ],
            'search' => [
                'volume' => 0,
                'unique_searchers' => [], // unique user_ids
                'zero_results' => 0,
                'top_terms' => [],
            ],
            'nugget' => [
                'saves' => 0,
                'shares' => 0,
                'likes' => 0,
            ],
            'users' => [
                'active' => 0, // unique user_ids
            ]
        ];

        foreach ($this->events as $event) {
            $eventType = $event['event_type'];
            $userId = $event['user_id'] ?? null;
            $props = $event['event_properties'] ?? [];

            if ($userId) {
                $metrics['users']['active'][$userId] = true;
                if ($eventType === 'Clicks Search') {
                    $metrics['search']['unique_searchers'][$userId] = true; 
                }
            }

            // Funnel Analysis (Mapped from prompt-mine-mobile source)
            switch ($eventType) {
                // Explore Flow
                case 'Prompt Type Selector Viewed':
                case 'Selects Category from Feed':
                    $metrics['funnel']['explore']['views']++;
                    break;
                case 'Select Prompt':
                    $metrics['funnel']['explore']['select']++;
                    break;
                case 'Try Prompt':
                    $metrics['funnel']['explore']['try']++;
                    break;
                case 'Prompt Editing': // Shared step, usually follows Try Prompt in Explore flow
                    $metrics['funnel']['explore']['edit']++;
                    break;

                // Composer Flow
                case 'Composer Launched': 
                case 'Landing Screen':
                    $metrics['funnel']['composer']['views']++;
                    break;
                case 'Clicks Create Card':
                    $metrics['funnel']['composer']['select']++;
                    break;
                case 'Enter Prompt': // or 'Video Prompt Text Entered'
                case 'Video Prompt Text Entered':
                    $metrics['funnel']['composer']['prompt']++;
                    break;

                // Generation (Success)
                case 'Generate Nugget ': // Note: Trailing space in source
                case 'Generate Nugget':
                case 'Chat response received':
                    // Assign to flow based on properties or heuristic
                    // For MVP, we increment both 'generate' counters to show total volume, 
                    // or check props['source'] if available.
                    if (($props['source'] ?? '') === 'Explore') {
                        $metrics['funnel']['explore']['generate']++;
                    } elseif (($props['source'] ?? '') === 'Composer') {
                        $metrics['funnel']['composer']['generate']++;
                    } else {
                        // If source undefined, assume split or log to generic
                        // Defaulting to Explore for now as it's the primary flow
                        $metrics['funnel']['explore']['generate']++;
                    }
                    break;
                
                // Search Metrics
                case 'Clicks Search':
                    $metrics['search']['volume']++;
                    if (($props['result_count'] ?? 1) === 0) $metrics['search']['zero_results']++;
                    $term = $props['search_term'] ?? '';
                    if ($term) {
                        $metrics['search']['top_terms'][$term] = ($metrics['search']['top_terms'][$term] ?? 0) + 1;
                    }
                    break;

                // Engagement
                case 'Save Nugget':
                    $metrics['nugget']['saves']++;
                    break;
                case 'Share prompt':
                    $metrics['nugget']['shares']++;
                    break;
                case 'Likes Prompt':
                    $metrics['nugget']['likes']++;
                    break;
            }
        }

        // Post-Processing (Counts to Integers)
        $metrics['users']['active'] = count($metrics['users']['active']);
        $metrics['search']['unique_searchers'] = count($metrics['search']['unique_searchers']);
        arsort($metrics['search']['top_terms']);
        $metrics['search']['top_terms'] = array_slice($metrics['search']['top_terms'], 0, 5); // Top 5

        return $metrics;
    }
}
