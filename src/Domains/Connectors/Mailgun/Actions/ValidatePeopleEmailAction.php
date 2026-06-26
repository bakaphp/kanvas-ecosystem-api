<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Actions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Mailgun\Services\EmailValidationService;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Enums\ContactValidationStatusEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as LedgerEventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Throwable;

class ValidatePeopleEmailAction
{
    private const array EMAIL_TYPES = [
        ContactTypeEnum::PRIMARY_EMAIL->value,
        ContactTypeEnum::EMAIL->value,
        ContactTypeEnum::SECONDARY_EMAIL->value,
    ];

    private EmailValidationService $validator;

    public function __construct(
        protected People $people,
        protected Apps $app,
        ?EmailValidationService $validator = null,
    ) {
        $this->validator = $validator ?? new EmailValidationService($app);
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $validated = [];

        foreach ($this->emailContacts() as $contact) {
            $result = $this->validator->validate($contact->value);
            $status = $result->toValidationStatus();

            if ($status !== null) {
                $this->applyStatus($contact, $status);
            }

            $validated[] = [
                'email' => $contact->value,
                'result' => $result->result,
                'risk' => $result->risk,
                'status' => $status?->value,
            ];
        }

        if ($validated !== []) {
            $this->emitLedgerEvent($validated);
        }

        return [
            'people_id' => $this->people->getId(),
            'validated' => $validated,
        ];
    }

    /**
     * @return Collection<int, Contact>
     */
    private function emailContacts(): Collection
    {
        return $this->people->contacts()
            ->whereIn('contacts_types_id', self::EMAIL_TYPES)
            ->get();
    }

    private function applyStatus(Contact $contact, ContactValidationStatusEnum $status): void
    {
        match ($status) {
            ContactValidationStatusEnum::VALID => $contact->markValid(),
            ContactValidationStatusEnum::HARD_BOUNCE => $contact->markBounce(permanent: true),
            ContactValidationStatusEnum::SOFT_BOUNCE => $contact->markBounce(permanent: false),
            ContactValidationStatusEnum::INVALID => $contact->markInvalid(),
        };
    }

    /**
     * Best-effort: a ledger outage must never fail a validation that already wrote the flag.
     *
     * @param list<array<string, mixed>> $validated
     */
    private function emitLedgerEvent(array $validated): void
    {
        $invalid = array_values(array_filter(
            $validated,
            fn (array $row): bool => in_array($row['status'], [
                ContactValidationStatusEnum::HARD_BOUNCE->value,
                ContactValidationStatusEnum::INVALID->value,
            ], true),
        ));

        try {
            new AppendEventAction(
                new LedgerEventData(
                    app: $this->app,
                    company: $this->people->company,
                    sourceDomain: 'Guild',
                    eventType: 'people.email_validated',
                    status: EventStatusEnum::INFO,
                    sourceEntityType: People::class,
                    sourceEntityId: (int) $this->people->getId(),
                    actorType: 'System',
                    payload: [
                        'source' => 'mailgun',
                        'company' => (string) ($this->people->get('company') ?? ''),
                        'validated' => $validated,
                        'invalid_count' => count($invalid),
                    ],
                    occurredAt: Carbon::now(),
                ),
            )->execute();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
