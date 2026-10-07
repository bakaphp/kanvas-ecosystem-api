<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\ContactType;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesPersonForTool;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Per-address only. Opting ONE address out is allowed here; silencing the whole person is
 * mark_person_do_not_contact, which goes through ApplyDoNotContactAction so every guard sees it.
 */
#[AgentTool(name: 'Manage Person Contact', category: 'crm')]
class ManagePersonContactTool extends Tool
{
    use ReportsToolOutcome;
    use ResolvesPersonForTool;
    use TrackByInputs;

    protected string $name = 'manage_person_contact';

    protected ?string $description = 'Add, correct, remove or verify one email, phone or LinkedIn profile on a person. action '
        . '"save" (default) adds the value or, if it already exists, updates its opt-out flag — it never '
        . 'duplicates. "remove" deletes a wrong one. "mark_valid" / "mark_invalid" record whether an email or '
        . 'phone is deliverable (invalid ones are skipped by every send). To correct a value, save the new one '
        . 'and remove the old one. kind is one of: email, secondary_email, phone, cellphone, work_phone, '
        . 'linkedin (profile URL). To stop contacting the person entirely use mark_person_do_not_contact.';

    private const array ACTIONS = ['save', 'remove', 'mark_valid', 'mark_invalid'];

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'person_id', type: PropertyType::INTEGER, description: 'The id of the person.', required: true),
            new ToolProperty(name: 'value', type: PropertyType::STRING, description: 'The email address, phone number or LinkedIn URL.', required: true),
            new ToolProperty(
                name: 'kind',
                type: PropertyType::STRING,
                description: 'The contact kind. Required for "save"; ignored otherwise.',
                required: false,
                enum: array_keys(self::kinds()),
            ),
            new ToolProperty(
                name: 'action',
                type: PropertyType::STRING,
                description: 'save (default), remove, mark_valid or mark_invalid.',
                required: false,
                enum: self::ACTIONS,
            ),
            new ToolProperty(
                name: 'is_opt_out',
                type: PropertyType::BOOLEAN,
                description: 'For "save": mark this address/number as do-not-contact. Defaults to false.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $person_id,
        string $value,
        ?string $kind = null,
        ?string $action = null,
        ?bool $is_opt_out = null,
    ): array {
        $value = trim($value);
        if ($value === '') {
            return $this->invalidArgs('Provide the email or phone value.');
        }

        $action = strtolower(trim($action ?? 'save'));
        if (! in_array($action, self::ACTIONS, true)) {
            return $this->invalidArgs('action must be one of: ' . implode(', ', self::ACTIONS) . '.');
        }

        $result = $this->resolvePersonOrError($person_id);
        if (is_array($result)) {
            return $result;
        }
        $person = $result;

        if ($action === 'save') {
            $type = self::kinds()[strtolower(trim($kind ?? ''))] ?? null;
            if ($type === null) {
                return $this->invalidArgs('kind must be one of: ' . implode(', ', array_keys(self::kinds())) . '.');
            }

            $contact = $person->addContact(
                $type,
                $value,
                $is_opt_out === true ? 1 : 0,
                0
            );

            return $this->ok($this->describe($person->getId(), $contact) + ['message' => 'Contact point saved.']);
        }

        /** @var Contact|null $contact */
        $contact = $person->contacts()
            ->whereIn('value', $this->storedForms($value))
            ->where('is_deleted', 0)
            ->first();
        if ($contact === null) {
            return $this->notFound(
                ['error' => sprintf('Person #%d has no contact "%s".', $person->getId(), $value)],
                guidance: 'Nothing was changed. Use get_person to see the person\'s current emails and phones.'
            );
        }

        match ($action) {
            'remove' => $contact->delete(),
            'mark_valid' => $contact->markValid(),
            'mark_invalid' => $contact->markInvalid(),
        };

        return $this->ok($this->describe($person->getId(), $contact) + [
            'message' => match ($action) {
                'remove' => 'Contact point removed.',
                'mark_valid' => 'Contact point marked valid.',
                'mark_invalid' => 'Contact point marked invalid; sends will skip it.',
            },
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(int $personId, Contact $contact): array
    {
        return [
            'person_id' => $personId,
            'contact' => [
                'value' => $contact->value,
                'kind' => $this->kindOf($contact),
                'validation_status' => $contact->validation_status?->value,
                'is_opt_out' => (bool) $contact->is_opt_out,
            ],
        ];
    }

    private function kindOf(Contact $contact): ?string
    {
        $kind = array_search($contact->type?->name, self::kinds(), true);

        return $kind === false ? null : $kind;
    }

    /**
     * kind → contacts_types.name. LinkedIn has no enum case; it is created on demand by name, the same
     * way Apollo enrichment writes it.
     *
     * @return array<string, string>
     */
    private static function kinds(): array
    {
        return [
            'email' => ContactTypeEnum::EMAIL->getName(),
            'secondary_email' => ContactTypeEnum::SECONDARY_EMAIL->getName(),
            'phone' => ContactTypeEnum::PHONE->getName(),
            'cellphone' => ContactTypeEnum::CELLPHONE->getName(),
            'work_phone' => ContactTypeEnum::WORK_PHONE->getName(),
            'linkedin' => ContactType::LINKEDIN,
        ];
    }

    /**
     * Phones are stored digits-only, so "+1 (809) 555-0199" has to be looked up as "18095550199".
     *
     * @return list<string>
     */
    private function storedForms(string $value): array
    {
        $digits = Contact::cleanPhone($value);
        $looksLikePhone = $digits !== '' && ! str_contains($value, '@') && ! str_contains($value, '/');

        return $looksLikePhone && $digits !== $value ? [$value, $digits] : [$value];
    }
}
