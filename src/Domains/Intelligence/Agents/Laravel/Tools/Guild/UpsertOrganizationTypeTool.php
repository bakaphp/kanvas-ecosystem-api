<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Guild;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Kanvas\Intelligence\Tools\Traits\Guild\UpsertOrganizationTypeTrait;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;

#[AgentTool(name: 'Upsert Organization Type')]
class UpsertOrganizationTypeTool implements KanvasToolInterface
{
    use HasKanvasContext;
    use UpsertOrganizationTypeTrait;

    #[Override]
    public function description(): Stringable|string
    {
        return 'Create a new organization type or update an existing one. Provide organization_type_id to update; omit it to create. Organization types categorize organizations (e.g. Client, Partner, Supplier).';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $result = $this->upsertOrganizationType(
            app: $this->app,
            company: $this->company,
            user: auth()->user(),
            name: (string) $request->string('name'),
            organizationTypeId: $request->integer('organization_type_id') ?: null,
            description: filled($request->string('description')) ? (string) $request->string('description') : null,
            isActive: (bool) ($request->all()['is_active'] ?? true),
            isDefault: (bool) ($request->all()['is_default'] ?? false),
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
            'organization_type_id' => $schema
                ->integer()
                ->description('ID of an existing organization type to update. Omit to create a new one.'),
            'name' => $schema
                ->string()
                ->description('Organization type name (e.g. Client, Partner, Supplier).')
                ->required(),
            'description' => $schema
                ->string()
                ->description('Optional description of what this organization type represents.'),
            'is_active' => $schema
                ->boolean()
                ->description('Whether this type is active and available for use. Defaults to true.'),
            'is_default' => $schema
                ->boolean()
                ->description('Whether this is the default type assigned to new organizations. Defaults to false.'),
        ];
    }
}
