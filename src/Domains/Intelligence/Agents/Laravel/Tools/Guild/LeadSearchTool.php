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

        $leads = Lead::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->where(function ($q) use ($query) {
                $q->where('title', 'LIKE', "%{$query}%")
                    ->orWhere('description', 'LIKE', "%{$query}%")
                    ->orWhere('firstname', 'LIKE', "%{$query}%");
            })
            ->where('created_at', '>=', now()->subDays($daysBack))
            ->limit(10)
            ->get(['id', 'title', 'description', 'firstname', 'lastname', 'created_at']);

        return json_encode([
            'leads' => $leads->map(fn (Lead $lead) => [
                'id' => $lead->getId(),
                'title' => $lead->title,
                'description' => $lead->description,
                'firstname' => $lead->firstname,
                'lastname' => $lead->lastname,
                'created_at' => $lead->created_at?->toIso8601String(),
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
        ];
    }
}
