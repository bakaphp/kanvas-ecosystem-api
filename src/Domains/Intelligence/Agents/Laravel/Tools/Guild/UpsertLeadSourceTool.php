<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Guild;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Kanvas\Intelligence\Tools\Traits\Guild\UpsertLeadSourceTrait;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;

#[AgentTool(name: 'Upsert Lead Source', category: 'crm')]
class UpsertLeadSourceTool implements KanvasToolInterface
{
    use HasKanvasContext;
    use UpsertLeadSourceTrait;

    #[Override]
    public function description(): Stringable|string
    {
        return 'Find or create a Lead Source by name. Returns the lead_source_id to be passed to create_lead. Use the SourceType from your analysis as the name (e.g. "Earnings Transcript", "News Article", "Press Release").';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $result = $this->upsertLeadSource(
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
                ->description('Lead source name. Use the SourceType from your analysis (e.g. "Earnings Transcript", "News Article", "Press Release", "Regulatory Filing").')
                ->required(),
            'description' => $schema
                ->string()
                ->description('Optional description of what this source type represents.'),
        ];
    }
}
