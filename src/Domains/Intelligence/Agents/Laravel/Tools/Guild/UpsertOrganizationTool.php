<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Guild;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Kanvas\Intelligence\Tools\Traits\Guild\UpsertOrganizationTrait;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;

class UpsertOrganizationTool implements KanvasToolInterface
{
    use HasKanvasContext;
    use UpsertOrganizationTrait;

    #[Override]
    public function description(): Stringable|string
    {
        return 'Create a new organization or update an existing one. Provide organization_id to update; omit it to create (returns existing if the name already exists for this company).';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $result = $this->upsertOrganization(
            app: $this->app,
            company: $this->company,
            user: auth()->user(),
            name: (string) $request->string('name'),
            organizationId: $request->integer('organization_id') ?: null,
            organizationTypeId: $request->integer('organization_type_id') ?: null,
            email: filled($request->string('email')) ? (string) $request->string('email') : null,
            phone: filled($request->string('phone')) ? (string) $request->string('phone') : null,
            address: filled($request->string('address')) ? (string) $request->string('address') : null,
            city: filled($request->string('city')) ? (string) $request->string('city') : null,
            state: filled($request->string('state')) ? (string) $request->string('state') : null,
            zip: filled($request->string('zip')) ? (string) $request->string('zip') : null,
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
