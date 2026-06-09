<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Guild;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;

#[AgentTool(name: 'Lead Search')]
class LeadSearchTool implements KanvasToolInterface
{
    use HasKanvasContext;

    #[Override]
    public function description(): Stringable|string
    {
        return 'Search existing leads by keyword or company name. Returns matching leads created within the specified time window.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $query = (string) $request->string('query');
        $daysBack = $request->integer('days_back') ?: 90;
        $eventSubType = $request->string('event_sub_type') ? (string) $request->string('event_sub_type') : null;
        $descriptionSnippet = $request->string('description_snippet') ? (string) $request->string('description_snippet') : null;
        $onlyPublished = $request->boolean('only_published', true);

        $leads = Lead::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->when($onlyPublished, fn ($q) => $q->where('is_published', 1))
            ->where(function ($q) use ($query) {
                $q->where('title', 'LIKE', "%{$query}%")
                    ->orWhere('description', 'LIKE', "%{$query}%")
                    ->orWhere('firstname', 'LIKE', "%{$query}%");
            })
            ->when($descriptionSnippet, fn ($q) => $q->where('description', 'LIKE', $descriptionSnippet . '%'))
            ->when($eventSubType, fn ($q) => $q->whereHas(
                'customFields',
                fn ($q) => $q->where('name', 'event_sub_type')->where('value', $eventSubType)
            ))
            ->where('created_at', '>=', now()->subDays($daysBack))
            ->limit(10)
            ->get(['id', 'title', 'description', 'firstname', 'lastname', 'created_at', 'is_published']);

        return json_encode([
            'leads' => $leads->map(fn (Lead $lead) => [
                'id' => $lead->getId(),
                'title' => $lead->title,
                'description' => $lead->description,
                'firstname' => $lead->firstname,
                'lastname' => $lead->lastname,
                'created_at' => $lead->created_at?->toIso8601String(),
                'event_type' => $lead->get('event_type'),
                'event_sub_type' => $lead->get('event_sub_type'),
                'severity_score' => $lead->get('severity_score'),
                'event_distress_score' => $lead->get('event_distress_score'),
                'realized_impact' => $lead->get('realized_impact') ?? 'unknown',
                'is_published' => (bool) $lead->is_published,
            ])->values()->all(),
        ], JSON_PRETTY_PRINT);
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema
                ->string()
                ->description('Company name or keywords to search for in existing leads.')
                ->required(),
            'days_back' => $schema
                ->integer()
                ->description('How many days back to search. Defaults to 90.'),
            'event_sub_type' => $schema
                ->string()
                ->description('Filter results to leads with this event_sub_type custom field (e.g. "Chapter 11 Bankruptcy"). Use to find similar past events for scoring context.'),
            'only_published' => $schema
                ->boolean()
                ->description('When true (default), excludes leads where is_published=0 (Unknown Company events). Pass false to include all leads regardless of published status.'),
            'description_snippet' => $schema
                ->string()
                ->description('First 150 characters of the event text verbatim. When provided, also searches for leads whose description starts with this exact string — used for reliable exact-duplicate detection before keyword-based reasoning.'),
        ];
    }
}
