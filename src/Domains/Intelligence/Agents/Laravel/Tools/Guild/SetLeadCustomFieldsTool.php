<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Guild;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Kanvas\Intelligence\Tools\Traits\Guild\SetsLeadCustomFieldsTrait;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;

#[AgentTool(name: 'Set Lead Custom Fields')]
class SetLeadCustomFieldsTool implements KanvasToolInterface
{
    use HasKanvasContext;
    use SetsLeadCustomFieldsTrait;

    #[Override]
    public function description(): Stringable|string
    {
        return 'Set one or more custom fields on an existing lead. Use this after create_lead to store domain-specific data such as event type, severity, scores, or any structured classification result.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $leadId = $request->integer('lead_id');

        $rawFields = $request->all()['fields'] ?? null;
        $fields = is_array($rawFields)
            ? $rawFields
            : (is_string($rawFields) ? (json_decode($rawFields, true) ?? []) : []);

        $result = $this->setLeadCustomFields(
            app: $this->app,
            company: $this->company,
            leadId: $leadId,
            fields: $fields,
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
            'lead_id' => $schema
                ->integer()
                ->description('The ID returned by create_lead.')
                ->required(),
            'fields' => $schema
                ->string()
                ->description('JSON-encoded object with key/value pairs to store as custom fields on the lead. Example: {"event_type":"Chapter11Bankruptcy","severity":"4"}')
                ->required(),
        ];
    }
}
