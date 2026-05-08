<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Guild;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People;
use Kanvas\Guild\Leads\Actions\CreateLeadAction;
use Kanvas\Guild\Leads\DataTransferObject\Lead as LeadData;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Laravel\Ai\Tools\Request;
use Override;
use Spatie\LaravelData\DataCollection;
use Stringable;
use Throwable;

class CreateLeadTool implements KanvasToolInterface
{
    use HasKanvasContext;

    #[Override]
    public function description(): Stringable|string
    {
        return 'Create a CRM lead with contact information. Returns the lead ID to be used for setting additional custom fields.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $user = auth()->user();
        $branch = $this->company->defaultBranch;

        $contacts = [];

        if (filled($request->string('email'))) {
            $contacts[] = new Contact(value: (string) $request->string('email'));
        }

        if (filled($request->string('phone'))) {
            $contacts[] = new Contact(value: (string) $request->string('phone'));
        }

        $firstname = (string) $request->string('firstname');
        $lastname = (string) $request->string('lastname');
        $title = (string) $request->string('title');
        $description = filled($request->string('description'))
            ? (string) $request->string('description')
            : null;

        $people = new People(
            app: $this->app,
            branch: $branch,
            user: $user,
            firstname: $firstname,
            contacts: Contact::collect($contacts, DataCollection::class),
            address: Address::collect([], DataCollection::class),
            lastname: $lastname ?: null,
        );

        $leadData = new LeadData(
            app: $this->app,
            branch: $branch,
            user: $user,
            title: $title,
            pipeline_stage_id: (int) $this->company->get('agent_lead_pipeline_stage_id', 0),
            people: $people,
            description: $description,
        );

        try {
            $lead = new CreateLeadAction($leadData)->execute();
        } catch (Throwable $e) {
            return "Failed to create lead: {$e->getMessage()}";
        }

        return json_encode([
            'lead_id' => $lead->getId(),
            'title' => $lead->title,
            'message' => "Lead '{$lead->title}' created successfully.",
        ], JSON_PRETTY_PRINT);
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
        ];
    }
}
