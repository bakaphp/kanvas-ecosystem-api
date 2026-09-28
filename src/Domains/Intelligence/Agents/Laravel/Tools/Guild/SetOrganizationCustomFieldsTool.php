<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Guild;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Kanvas\Intelligence\Tools\Traits\Guild\SetsOrganizationCustomFieldsTrait;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;

#[AgentTool(name: 'Set Organization Custom Fields', category: 'crm')]
class SetOrganizationCustomFieldsTool implements KanvasToolInterface
{
    use HasKanvasContext;
    use SetsOrganizationCustomFieldsTrait;

    #[Override]
    public function description(): Stringable|string
    {
        return 'Set one or more custom fields on an existing organization. Use this to store domain-specific data such as industry, revenue, status, scores, or any structured classification result.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $organizationId = $request->integer('organization_id');

        $rawFields = $request->all()['fields'] ?? null;
        $fields = is_array($rawFields)
            ? $rawFields
            : (is_string($rawFields) ? (json_decode($rawFields, true) ?? []) : []);

        $result = $this->setOrganizationCustomFields(
            app: $this->app,
            company: $this->company,
            organizationId: $organizationId,
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
            'organization_id' => $schema
                ->integer()
                ->description('The ID of the organization to update.')
                ->required(),
            'fields' => $schema
                ->object()
                ->description('Object with key/value pairs to store as custom fields on the organization. Values can be strings, numbers, booleans, arrays, or nested objects. Example: {"industry":"Technology","annual_revenue":5000000,"company_profile":{...}}')
                ->required(),
        ];
    }
}
