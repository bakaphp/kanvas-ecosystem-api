<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Guild;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Guild\Leads\Models\LeadSource;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;
use Throwable;

class UpsertLeadSourceTool implements KanvasToolInterface
{
    use HasKanvasContext;

    #[Override]
    public function description(): Stringable|string
    {
        return 'Find or create a Lead Source by name. Returns the lead_source_id to be passed to create_lead. Use the SourceType from your analysis as the name (e.g. "Earnings Transcript", "News Article", "Press Release").';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $name = (string) $request->string('name');
        $description = filled($request->string('description')) ? (string) $request->string('description') : $name;

        try {
            $existing = LeadSource::query()
                ->where('apps_id', $this->app->getId())
                ->where('companies_id', $this->company->getId())
                ->where('name', $name)
                ->where('is_deleted', 0)
                ->first();

            if ($existing) {
                return json_encode([
                    'lead_source_id' => $existing->getId(),
                    'name' => $existing->name,
                    'action' => 'found',
                ], JSON_PRETTY_PRINT);
            }

            $leadSource = new LeadSource();
            $leadSource->apps_id = $this->app->getId();
            $leadSource->companies_id = $this->company->getId();
            $leadSource->name = $name;
            $leadSource->description = $description;
            $leadSource->is_active = 1;
            $leadSource->saveOrFail();
        } catch (Throwable $e) {
            return "Failed to upsert lead source: {$e->getMessage()}";
        }

        return json_encode([
            'lead_source_id' => $leadSource->getId(),
            'name' => $leadSource->name,
            'action' => 'created',
        ], JSON_PRETTY_PRINT);
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema
                ->string()
                ->description('Lead source name. Use the SourceType from your analysis (e.g. "Earnings Transcript", "News Article", "Press Release", "Regulatory Filing").')
                ->required(),
            'description' => $schema
                ->string()
                ->description('Optional description of what this source type represents.'),
        ];
    }
}
