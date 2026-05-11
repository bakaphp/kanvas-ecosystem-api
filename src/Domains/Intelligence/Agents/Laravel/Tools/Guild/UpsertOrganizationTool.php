<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Guild;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Guild\Organizations\Actions\CreateOrganizationAction;
use Kanvas\Guild\Organizations\Actions\UpdateOrganizationAction;
use Kanvas\Guild\Organizations\DataTransferObject\Organization as OrganizationData;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Models\OrganizationType;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;
use Throwable;

class UpsertOrganizationTool implements KanvasToolInterface
{
    use HasKanvasContext;

    #[Override]
    public function description(): Stringable|string
    {
        return 'Create a new organization or update an existing one. Provide organization_id to update; omit it to create (returns existing if the name already exists for this company).';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $organizationId = $request->integer('organization_id') ?: null;
        $name = (string) $request->string('name');

        $orgData = new OrganizationData(
            company: $this->company,
            user: auth()->user(),
            app: $this->app,
            name: $name,
            email: filled($request->string('email')) ? (string) $request->string('email') : null,
            phone: filled($request->string('phone')) ? (string) $request->string('phone') : null,
            address: filled($request->string('address')) ? (string) $request->string('address') : null,
            city: filled($request->string('city')) ? (string) $request->string('city') : null,
            state: filled($request->string('state')) ? (string) $request->string('state') : null,
            zip: filled($request->string('zip')) ? (string) $request->string('zip') : null,
        );

        $organizationTypeId = $request->integer('organization_type_id') ?: null;

        try {
            if ($organizationId !== null) {
                $organization = Organization::getByIdFromCompanyApp($organizationId, $this->company, $this->app);
                $organization = new UpdateOrganizationAction($organization, $orgData)->execute();
                $action = 'updated';
            } else {
                $organization = new CreateOrganizationAction($orgData)->execute();
                $action = 'created';

                if ($organizationTypeId !== null) {
                    $organizationType = OrganizationType::getByIdFromCompanyApp($organizationTypeId, $this->company, $this->app);
                    $organization->organization_type_id = $organizationType->getId();
                    $organization->save();
                }
            }
        } catch (Throwable $e) {
            return "Failed to upsert organization: {$e->getMessage()}";
        }

        return json_encode([
            'organization_id' => $organization->getId(),
            'name' => $organization->name,
            'organization_type_id' => $organization->organization_type_id,
            'action' => $action,
        ], JSON_PRETTY_PRINT);
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'organization_id' => $schema
                ->integer()
                ->description('ID of an existing organization to update. Omit to create a new one.'),
            'name' => $schema
                ->string()
                ->description('Organization name.')
                ->required(),
            'address' => $schema
                ->string()
                ->description('Street address.'),
            'city' => $schema
                ->string()
                ->description('City.'),
            'state' => $schema
                ->string()
                ->description('State or province.'),
            'zip' => $schema
                ->string()
                ->description('ZIP or postal code.'),
            'email' => $schema
                ->string()
                ->description('Organization contact email.'),
            'phone' => $schema
                ->string()
                ->description('Organization contact phone number.'),
            'organization_type_id' => $schema
                ->integer()
                ->description('ID of an organization type to assign when creating a new organization. Has no effect on updates.'),
        ];
    }
}
