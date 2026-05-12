<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Guild;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Guild\Organizations\Actions\CreateOrganizationTypeAction;
use Kanvas\Guild\Organizations\Actions\UpdateOrganizationTypeAction;
use Kanvas\Guild\Organizations\DataTransferObject\OrganizationType as OrganizationTypeData;
use Kanvas\Guild\Organizations\Models\OrganizationType;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;
use Throwable;

class UpsertOrganizationTypeTool implements KanvasToolInterface
{
    use HasKanvasContext;

    #[Override]
    public function description(): Stringable|string
    {
        return 'Create a new organization type or update an existing one. Provide organization_type_id to update; omit it to create. Organization types categorize organizations (e.g. Client, Partner, Supplier).';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $organizationTypeId = $request->integer('organization_type_id') ?: null;
        $name = (string) $request->string('name');

        $data = new OrganizationTypeData(
            apps: $this->app,
            companies: $this->company,
            user: auth()->user(),
            name: $name,
            description: filled($request->string('description')) ? (string) $request->string('description') : null,
            is_active: (bool) ($request->all()['is_active'] ?? true),
            is_default: (bool) ($request->all()['is_default'] ?? false),
        );

        try {
            if ($organizationTypeId !== null) {
                $organizationType = OrganizationType::getByIdFromCompanyApp($organizationTypeId, $this->company, $this->app);
                $organizationType = new UpdateOrganizationTypeAction($organizationType, $data)->execute();
                $action = 'updated';
            } else {
                $organizationType = new CreateOrganizationTypeAction($data)->execute();
                $action = 'created';
            }
        } catch (Throwable $e) {
            return "Failed to upsert organization type: {$e->getMessage()}";
        }

        return json_encode([
            'organization_type_id' => $organizationType->getId(),
            'name' => $organizationType->name,
            'slug' => $organizationType->slug,
            'is_active' => (bool) $organizationType->is_active,
            'action' => $action,
        ], JSON_PRETTY_PRINT);
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
