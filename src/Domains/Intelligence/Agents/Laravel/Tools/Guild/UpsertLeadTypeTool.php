<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Guild;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Kanvas\Intelligence\Tools\Traits\Guild\UpsertLeadTypeTrait;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;

class UpsertLeadTypeTool implements KanvasToolInterface
{
    use HasKanvasContext;
    use UpsertLeadTypeTrait;

    #[Override]
    public function description(): Stringable|string
    {
        return 'Find or create a Lead Type by name. Returns the lead_type_id to be passed to create_lead. Use the EventType from your analysis as the name (e.g. "Liquidity", "Legal", "Operational").';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $result = $this->upsertLeadType(
            app: $this->app,
            company: $this->company,
            name: (string) $request->string('name'),
            description: filled($request->string('description')) ? (string) $request->string('description') : null,
        );

        if (isset($result['error'])) {
            return $result['error'];
        }

        return json_encode($result, JSON_PRETTY_PRINT);
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
