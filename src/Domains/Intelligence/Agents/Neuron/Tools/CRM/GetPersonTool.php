<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Models\PeopleEmploymentHistory;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ExposesPersonCustomFields;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * The full profile of one person by id: emails and phones (with deliverability + opt-out state),
 * title, organizations, tags, addresses, employment history, linked leads, and scrubbed custom
 * fields. Company-wide read — an internal-teammate capability, NOT the customer-facing surface.
 */
#[AgentTool(name: 'Get Person', category: 'crm')]
class GetPersonTool extends Tool implements HasRunKey
{
    use ExposesPersonCustomFields;
    use HasKanvasContext;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'get_person',
            description: 'Returns the full profile of one person/contact by person_id: emails & phones (with '
                . 'deliverability and opt-out state), title, organizations, tags, addresses, employment history, the '
                . 'leads they are linked to, and their business custom fields. Use find_person first to get the id.',
        );
    }

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
        try {
            /** @var People $person */
            $person = People::getByIdFromCompanyApp($person_id, $this->company, $this->app);
        } catch (Throwable) {
            return ['error' => sprintf('No person #%d found in this company.', $person_id)];
        }

        $person->load([
            'contacts',
            'organizations' => fn ($q) => $q->select('organizations.id', 'name'),
            'employmentHistory',
            'leads',
        ]);

        return [
            'person_id' => $person->getId(),
            'name' => $person->getName(),
            'firstname' => $person->firstname,
            'lastname' => $person->lastname,
            'title' => $person->get('title') ?: null,
            'emails' => $person->contacts
                ->filter(fn (Contact $c): bool => str_contains($c->value, '@'))
                ->map(fn (Contact $c): array => [
                    'value' => $c->value,
                    'validation_status' => $c->validation_status?->value,
                    'is_opt_out' => (bool) $c->is_opt_out,
                ])->values()->all(),
            'phones' => $person->contacts
                ->filter(fn (Contact $c): bool => ! str_contains($c->value, '@'))
                ->map(fn (Contact $c): array => [
                    'value' => $c->value,
                    'is_opt_out' => (bool) $c->is_opt_out,
                ])->values()->all(),
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
                    'is_open' => $lead->isOpen(),
                ])->all(),
            'custom_fields' => $this->relevantCustomFields($person),
        ];
    }
}
