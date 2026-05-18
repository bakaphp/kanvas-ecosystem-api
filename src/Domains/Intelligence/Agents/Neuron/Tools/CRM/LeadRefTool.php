<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

class LeadRefTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            'get_lead_ref',
            'Get the full reference data of the lead including personal info, owner,
             company, contacts (emails, phones), address, and photo.
             Call this once at the start of the conversation to know who you are talking to. Do not call it again.',
        );

        // $this->setMaxRuns(1);
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'lead_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the lead provided in the conversation context.',
                required: true,
            ),
        ];
    }

    public function __invoke(int $lead_id): string
    {
        $lead = Lead::getById($lead_id);
        /** @var \Kanvas\Companies\Models\Companies|null $company */
        $company = $lead->company;
        $people = $lead->people;

        $additional_context_information = $lead->get(LeadCustomFieldEnum::VEHICLE_OF_INTEREST->value);

        return json_encode([
            'lead_id' => $lead->id,
            'lead_uuid' => $lead->uuid,
            'title' => $lead->title,
            'firstname' => $lead->firstname,
            'lastname' => $lead->lastname,
            'email' => $lead->email,
            'phone' => $lead->phone,
            'description' => $lead->description,
            'status' => $lead->status()->first()?->name,
            'source' => $lead->source()->first()?->name,
            'type' => $lead->type()->first()?->name,
            'pipeline' => $lead->pipeline()->first()?->name,
            'stage' => $lead->stage()->first()?->name,
            'is_handed_off' => (bool) ($lead->get(ConfigurationEnum::AGENT_HAND_OFF->value) ?? false),

            'owner' => $lead->owner ? [
                'id' => $lead->owner->id,
                'name' => trim($lead->owner->firstname . ' ' . $lead->owner->lastname),
                'email' => $lead->owner->email,
            ] : null,
            'vehicle_interest' => $additional_context_information,
            'people' => $people ? [
                'id' => $people->id,
                'name' => $people->getName(),
                'firstname' => $people->firstname,
                'middlename' => $people->middlename,
                'lastname' => $people->lastname,
                'dob' => $people->dob?->format('Y-m-d'),
                'photo' => $people->getPhoto()?->url,

                'contacts' => $people->contacts()->with('type')->get()->map(fn ($contact) => [
                    'type' => $contact->type?->name,
                    'value' => $contact->value,
                    'is_opt_out' => (bool) $contact->is_opt_out,
                ])->toArray(),

                'addresses' => $people->address()->get()->map(fn ($address) => [
                    'address' => $address->address,
                    'address_2' => $address->address_2,
                    'city' => $address->city,
                    'state' => $address->state,
                    'country' => $address->country?->name,
                    'zip' => $address->zip,
                    'is_default' => (bool) $address->is_default,
                ])->toArray(),
            ] : null,

            'company' => $company ? [
                'id' => $company->id,
                'uuid' => $company->uuid,
                'name' => $company->name,
                'email' => $company->email,
                'phone' => $company->phone,
                'website' => $company->website,
                'address' => $company->address,
                'address_2' => $company->address_2,
                'city' => $company->city,
                'state' => $company->state,
                'country' => $company->country,
                'zip' => $company->zip,
                'zipcode' => $company->zipcode,
                'country_code' => $company->country_code,
                'timezone' => $company->timezone,
                'language' => $company->language,
                'is_active' => (bool) $company->is_active,
                'photo' => $company->getPhoto()?->url,
            ] : null,
        ]);
    }
}
