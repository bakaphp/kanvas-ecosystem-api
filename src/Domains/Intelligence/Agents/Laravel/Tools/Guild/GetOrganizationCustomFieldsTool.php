<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Guild;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Kanvas\Intelligence\Tools\Traits\Guild\GetsOrganizationCustomFieldsTrait;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;

#[AgentTool(name: 'Get Organization Custom Fields')]
class GetOrganizationCustomFieldsTool implements KanvasToolInterface
{
    use GetsOrganizationCustomFieldsTrait;
    use HasKanvasContext;

    #[Override]
    public function description(): Stringable|string
    {
        return 'Read custom fields stored on an organization. Returns all fields or a specific subset. Use this to check previously saved data (scores, company profile, timestamps) before deciding whether to re-fetch from external APIs.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $organizationId = $request->integer('organization_id');

        $rawKeys = $request->all()['keys'] ?? [];
        $keys = is_array($rawKeys)
            ? $rawKeys
            : (is_string($rawKeys) ? (json_decode($rawKeys, true) ?? []) : []);

        $result = $this->getOrganizationCustomFields(
            app: $this->app,
            company: $this->company,
            organizationId: $organizationId,
            keys: $keys,
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
                ->description('The ID of the organization to read from.')
                ->required(),
            'keys' => $schema
                ->array()
                ->items($schema->string())
                ->description('Optional list of specific field names to retrieve. If omitted, returns all custom fields. Example: ["composite_last_recomputed_at", "company_profile", "composite_distress_score"]'),
        ];
    }
}
