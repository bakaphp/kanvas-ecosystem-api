<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * Points a lead at a DIFFERENT existing contact (People) — e.g. the lead was attached to the wrong
 * person, or should be tied to another contact at the same company. Identifies the new contact by
 * people_id or by an email/phone that already exists on a contact in this company; it does NOT create
 * a new person (use the lead-capture/create flow for a brand-new contact). Keeps the lead's
 * denormalized name/email in sync so the card doesn't show the old person. Company-wide write — an
 * internal-teammate capability, NOT for the customer-facing prospect surface.
 */
#[AgentTool(name: 'Set Lead Contact', category: 'crm')]
class SetLeadContactTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'set_lead_contact',
            description: 'Change which contact person (People) a lead is associated with. Provide the lead_id plus '
                . 'either people_id or a contact (email or phone) that already exists in this company. Use this when '
                . 'a lead is on the wrong person or should move to a different contact. The contact must already '
                . 'exist — if it does not, create it first. Use search_leads to get the lead_id.',
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
                name: 'lead_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the lead whose contact person you want to change.',
                required: true,
            ),
            new ToolProperty(
                name: 'people_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the existing contact (People) to attach. Use this when you know it.',
                required: false,
            ),
            new ToolProperty(
                name: 'contact',
                type: PropertyType::STRING,
                description: 'An email or phone of the existing contact to attach, when you do not have the people_id.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $lead_id, ?int $people_id = null, ?string $contact = null): array
    {
        $contact = $contact !== null ? trim($contact) : null;
        if ($people_id === null && ($contact === null || $contact === '')) {
            return ['error' => 'Provide people_id or a contact (email or phone) to identify the new contact person.'];
        }

        try {
            /** @var Lead $lead */
            $lead = Lead::getByIdFromCompanyApp($lead_id, $this->company, $this->app);
        } catch (Throwable) {
            return ['error' => sprintf('Lead #%d not found in this company.', $lead_id)];
        }

        if ($people_id !== null) {
            try {
                /** @var People $people */
                $people = People::getByIdFromCompanyApp($people_id, $this->company, $this->app);
            } catch (Throwable) {
                return ['error' => sprintf('No contact #%d found in this company.', $people_id)];
            }
        } else {
            $people = PeoplesRepository::getByValue($contact, $this->company, $this->app);
            if ($people === null) {
                return ['error' => sprintf('No existing contact found with "%s". Create the contact first, then retry.', $contact)];
            }
        }

        $previous = $lead->people;

        $lead->people_id = $people->getId();
        $lead->firstname = $people->firstname;
        $lead->lastname = $people->lastname;
        $lead->email = $people->getEmails()->first()?->value ?? $lead->email ?? '';
        $lead->saveOrFail();

        return [
            'lead_id' => $lead->getId(),
            'title' => $lead->title,
            'previous_contact' => $previous ? [
                'id' => $previous->getId(),
                'name' => $previous->getName(),
            ] : null,
            'contact' => [
                'id' => $people->getId(),
                'name' => $people->getName(),
                'email' => $people->getEmails()->first()?->value,
            ],
            'message' => 'Lead contact person updated.',
        ];
    }
}
