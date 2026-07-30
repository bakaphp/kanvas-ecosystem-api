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

#[AgentTool(name: 'Lead Search', category: 'crm')]
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
        $exactField = $request->string('exact_field') ? (string) $request->string('exact_field') : null;
        $exactValue = $request->string('exact_value') ? (string) $request->string('exact_value') : null;
        $customFields = array_filter((array) ($request['custom_fields'] ?? []));

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
            ->when(
                $exactField && $exactValue,
                fn ($q) => $q->where($exactField, $exactValue)
            )
            ->when($eventSubType, fn ($q) => $q->whereHas(
                'customFields',
                fn ($q) => $q->where('name', 'event_sub_type')->where('value', $eventSubType)
            ))
            ->where('created_at', '>=', now()->subDays($daysBack))
            ->limit(10)
            ->get(['id', 'title', 'description', 'firstname', 'lastname', 'created_at', 'is_published']);

        return json_encode([
            'leads' => $leads->map(function (Lead $lead) use ($customFields) {
                $data = [
                    'id' => $lead->getId(),
                    'title' => $lead->title,
                    'description' => $lead->description,
                    'firstname' => $lead->firstname,
                    'lastname' => $lead->lastname,
                    'created_at' => $lead->created_at?->toIso8601String(),
                    'is_published' => (bool) $lead->is_published,
                ];

                foreach ($customFields as $field) {
                    $data[$field] = $lead->get((string) $field);
                }

                return $data;
            })->values()->all(),
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
            'exact_field' => $schema
                ->string()
                ->description('Column name to match exactly (e.g. "description", "title"). Must be used together with exact_value. When both are provided, adds a WHERE {exact_field} = {exact_value} condition for deterministic duplicate detection.'),
            'exact_value' => $schema
                ->string()
                ->description('Exact value to match against exact_field. Pass the complete field content (e.g. the full event description). Must be used together with exact_field.'),
            'custom_fields' => $schema
                ->array()
                ->items($schema->string())
                ->description('Optional list of custom field names to include in each lead result (e.g. ["event_distress_score", "event_type", "severity_score"]). Only the fields you name are fetched — omit this parameter to get base fields only.'),
        ];
    }
}
