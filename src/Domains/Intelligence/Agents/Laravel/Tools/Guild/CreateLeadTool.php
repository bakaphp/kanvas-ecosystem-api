<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Guild;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Kanvas\Intelligence\Tools\Traits\Guild\CreatesLeadTrait;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;

#[AgentTool(name: 'Create Lead', category: 'crm')]
class CreateLeadTool implements KanvasToolInterface
{
    use CreatesLeadTrait;
    use HasKanvasContext;

    #[Override]
    public function description(): Stringable|string
    {
        return 'Create a CRM lead with contact information. Returns the lead ID to be used for setting additional custom fields.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $result = $this->createLead(
            app: $this->app,
            company: $this->company,
            user: auth()->user(),
            title: (string) $request->string('title'),
            firstname: (string) $request->string('firstname'),
            lastname: filled($request->string('lastname')) ? (string) $request->string('lastname') : null,
            email: filled($request->string('email')) ? (string) $request->string('email') : null,
            phone: filled($request->string('phone')) ? (string) $request->string('phone') : null,
            description: filled($request->string('description')) ? (string) $request->string('description') : null,
            leadTypeId: $request->integer('lead_type_id') ?: 0,
            leadSourceId: $request->integer('lead_source_id') ?: 0,
            organizationId: $request->integer('organization_id') ?: null,
            organizationName: filled($request->string('organization_name')) ? (string) $request->string('organization_name') : null,
            isPublished: $request->has('is_published') ? (bool) $request->boolean('is_published') : true,
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
            'title' => $schema
                ->string()
                ->description('Lead title summarizing the reason for the lead.')
                ->required(),
            'firstname' => $schema
                ->string()
                ->description('First name of the contact or the company name if no individual contact is available.')
                ->required(),
            'lastname' => $schema
                ->string()
                ->description('Last name of the contact. Leave empty for company-only leads.'),
            'email' => $schema
                ->string()
                ->description('Contact email address, if available.'),
            'phone' => $schema
                ->string()
                ->description('Contact phone number, if available.'),
            'description' => $schema
                ->string()
                ->description('Full context or notes about this lead.'),
            'lead_type_id' => $schema
                ->integer()
                ->description('ID returned by upsert_lead_type. Classifies the lead by event category.'),
            'lead_source_id' => $schema
                ->integer()
                ->description('ID returned by upsert_lead_source. Indicates where the information came from.'),
            'organization_id' => $schema
                ->integer()
                ->description('ID returned by upsert_organization. Links the lead to the company being analyzed.'),
            'organization_name' => $schema
                ->string()
                ->description('Company the contact belongs to. Created if new, and the contact is added to it. Ignored when organization_id is given.'),
            'is_published' => $schema
                ->boolean()
                ->description('Whether this lead is visible in the standard lead list. Pass false (0) when the company could not be identified ("Unknown Company") so the event is hidden pending manual review. Defaults to true.'),
        ];
    }
}
