<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * Adds or corrects a single email/phone on a person, and sets its opt-out flag. Idempotent on
 * (person, value, type) — passing an existing value updates its opt-out instead of duplicating.
 * Use this to opt a person out of a specific address (the person-level equivalent of stop_contact,
 * which is lead-scoped). Company-wide write — an internal-teammate capability.
 */
#[AgentTool(name: 'Manage Person Contact', category: 'crm')]
class ManagePersonContactTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'manage_person_contact',
            description: 'Add or correct one email or phone on a person, and optionally mark it opt-out (do-not-'
                . 'contact). kind is "email", "phone" or "cellphone". Passing a value that already exists just '
                . 'updates its opt-out flag — it never duplicates. Identify the person by person_id.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'person_id', type: PropertyType::INTEGER, description: 'The id of the person.', required: true),
            new ToolProperty(
                name: 'kind',
                type: PropertyType::STRING,
                description: 'The contact kind: "email", "phone" (landline) or "cellphone".',
                required: true,
                enum: ['email', 'phone', 'cellphone'],
            ),
            new ToolProperty(name: 'value', type: PropertyType::STRING, description: 'The email address or phone number.', required: true),
            new ToolProperty(
                name: 'is_opt_out',
                type: PropertyType::BOOLEAN,
                description: 'Mark this address/number as do-not-contact. Defaults to false.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $person_id, string $kind, string $value, ?bool $is_opt_out = null): array
    {
        $value = trim($value);
        if ($value === '') {
            return ['error' => 'Provide the email or phone value.'];
        }

        $kind = strtolower(trim($kind));
        if (! in_array($kind, ['email', 'phone', 'cellphone'], true)) {
            return ['error' => 'kind must be one of: email, phone, cellphone.'];
        }

        try {
            /** @var People $person */
            $person = People::getByIdFromCompanyApp($person_id, $this->company, $this->app);
        } catch (Throwable) {
            return ['error' => sprintf('No person #%d found in this company.', $person_id)];
        }

        $optOut = $is_opt_out === true ? 1 : 0;

        $contact = match ($kind) {
            'email' => $person->addEmail($value, $optOut, 0),
            'phone' => $person->addPhone($value, $optOut, 0),
            'cellphone' => $person->addCellPhone($value, $optOut, 0),
        };

        return [
            'person_id' => $person->getId(),
            'contact' => [
                'value' => $contact->value,
                'kind' => $kind,
                'is_opt_out' => (bool) $contact->is_opt_out,
            ],
            'message' => 'Contact point saved.',
        ];
    }
}
