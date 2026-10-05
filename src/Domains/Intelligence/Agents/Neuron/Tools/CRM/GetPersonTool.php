<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Guild\Customers\Enums\ConsentConfigurationEnum;
use Kanvas\Guild\Customers\Models\Address;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\ContactType;
use Kanvas\Guild\Customers\Models\PeopleEmploymentHistory;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ExposesCustomFields;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HandlesAddressesForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesPersonForTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * The full profile of one person by id: emails and phones (with deliverability + opt-out state),
 * title, organizations, tags, addresses, employment history, linked leads, and scrubbed custom
 * fields. Company-wide read — an internal-teammate capability, NOT the customer-facing surface.
 */
#[AgentTool(name: 'Get Person', category: 'crm')]
class GetPersonTool extends Tool
{
    use ExposesCustomFields;
    use HasKanvasContext;
    use HandlesAddressesForTool;
    use ResolvesPersonForTool;
    use TrackByInputs;

    protected string $name = 'get_person';

    protected ?string $description = 'Returns the full profile of one person/contact by person_id: emails & phones (with '
        . 'deliverability and opt-out state), title, people type, LinkedIn, do-not-contact flag, '
        . 'organizations, tags, addresses, employment history, the '
        . 'leads they are linked to, and their business custom fields. Use find_person first to get the id.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'person_id',
                type: PropertyType::INTEGER,
                description: 'The id of the person to read.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $person_id): array
    {
        $result = $this->resolvePersonOrError($person_id);
        if (is_array($result)) {
            return $result;
        }
        $person = $result;

        $person->load([
            'contacts.type',
            'address.type',
            'address.country',
            'peopleType',
            'organizations' => fn ($q) => $q->select('organizations.id', 'name'),
            'employmentHistory',
            'leads.status',
        ]);

        return [
            'person_id' => $person->getId(),
            'name' => $person->getName(),
            'firstname' => $person->firstname,
            'lastname' => $person->lastname,
            'title' => $person->get('title') ?: null,
            'dob' => $person->dob,
            'people_type' => $person->peopleType?->name,
            'linkedin' => $person->contacts
                ->first(fn (Contact $c): bool => $c->type?->name === ContactType::LINKEDIN)
                ?->value,
            'do_not_contact' => (bool) $person->get(ConsentConfigurationEnum::DO_NOT_CONTACT->value),
            'emails' => $person->contacts
                ->filter(fn (Contact $c): bool => str_contains($c->value, '@'))
                ->map(fn (Contact $c): array => [
                    'value' => $c->value,
                    'type' => $c->type?->name,
                    'validation_status' => $c->validation_status?->value,
                    'is_opt_out' => (bool) $c->is_opt_out,
                ])->values()->all(),
            'phones' => $person->contacts
                ->filter(
                    fn (Contact $c): bool => ! str_contains($c->value, '@') && $c->type?->name !== ContactType::LINKEDIN
                )
                ->map(fn (Contact $c): array => [
                    'value' => $c->value,
                    'type' => $c->type?->name,
                    'validation_status' => $c->validation_status?->value,
                    'is_opt_out' => (bool) $c->is_opt_out,
                ])->values()->all(),
            'addresses' => $person->address
                ->map(fn (Address $a): array => $this->presentAddress($a))
                ->values()->all(),
            'organizations' => $person->organizations
                ->map(fn (Organization $o): array => ['organization_id' => $o->getId(), 'name' => $o->name])
                ->all(),
            'tags' => $person->tags->pluck('name')->all(),
            'employment_history' => $person->employmentHistory
                ->map(fn (PeopleEmploymentHistory $e): array => [
                    'organization_id' => $e->organizations_id,
                    'position' => $e->position,
                    'start_date' => $e->start_date,
                    'end_date' => $e->end_date,
                    'current' => (int) $e->status === 1,
                ])->all(),
            'linked_leads' => $person->leads
                ->map(fn (Lead $lead): array => [
                    'lead_id' => $lead->getId(),
                    'title' => $lead->title,
                    'status' => $lead->statusName(),
                    'is_open' => $lead->hasOpenLeadStatus(),
                ])->all(),
            'custom_fields' => $this->relevantCustomFields($person),
        ];
    }
}
