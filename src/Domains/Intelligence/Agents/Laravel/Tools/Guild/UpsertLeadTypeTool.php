<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Guild;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Guild\Leads\Actions\CreateLeadTypeAction;
use Kanvas\Guild\Leads\DataTransferObject\LeadType as LeadTypeData;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;
use Throwable;

class UpsertLeadTypeTool implements KanvasToolInterface
{
    use HasKanvasContext;

    #[Override]
    public function description(): Stringable|string
    {
        return 'Find or create a Lead Type by name. Returns the lead_type_id to be passed to create_lead. Use the EventType from your analysis as the name (e.g. "Liquidity", "Legal", "Operational").';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $name = (string) $request->string('name');
        $description = filled($request->string('description')) ? (string) $request->string('description') : $name;

        try {
            $existing = LeadType::query()
                ->where('apps_id', $this->app->getId())
                ->where('companies_id', $this->company->getId())
                ->where('name', $name)
                ->where('is_deleted', 0)
                ->first();

            if ($existing) {
                return json_encode([
                    'lead_type_id' => $existing->getId(),
                    'name' => $existing->name,
                    'action' => 'found',
                ], JSON_PRETTY_PRINT);
            }

            $leadType = new CreateLeadTypeAction(
                new LeadTypeData(
                    apps: $this->app,
                    companies: $this->company,
                    name: $name,
                    description: $description,
                    is_active: 1,
                ),
            )->execute();
        } catch (Throwable $e) {
            return "Failed to upsert lead type: {$e->getMessage()}";
        }

        return json_encode([
            'lead_type_id' => $leadType->getId(),
            'name' => $leadType->name,
            'action' => 'created',
        ], JSON_PRETTY_PRINT);
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema
                ->string()
                ->description('Lead type name. Use the EventType from your analysis (e.g. "Liquidity", "Legal", "Operational", "Strategic", "Governance", "Market", "Macroeconomic").')
                ->required(),
            'description' => $schema
                ->string()
                ->description('Optional description of what this lead type represents.'),
        ];
    }
}
