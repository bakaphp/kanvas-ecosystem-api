<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Guild;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;
use Throwable;

class SetOrganizationCustomFieldsTool implements KanvasToolInterface
{
    use HasKanvasContext;

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

        if (empty($fields)) {
            return 'No fields provided. Pass a "fields" JSON string with key/value pairs.';
        }

        try {
            /** @var Organization $organization */
            $organization = Organization::getByIdFromCompanyApp($organizationId, $this->company, $this->app);
        } catch (Throwable $e) {
            return "Organization {$organizationId} not found: {$e->getMessage()}";
        }

        foreach ($fields as $key => $value) {
            $organization->set((string) $key, $value);
        }

        return json_encode([
            'success' => true,
            'organization_id' => $organizationId,
            'fields_set' => count($fields),
        ], JSON_PRETTY_PRINT);
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
                ->string()
                ->description('JSON-encoded object with key/value pairs to store as custom fields on the organization. Example: {"industry":"Technology","annual_revenue":"5000000"}')
                ->required(),
        ];
    }
}
