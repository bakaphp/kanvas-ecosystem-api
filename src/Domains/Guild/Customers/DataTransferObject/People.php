<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use DateTime;
use Kanvas\Companies\Models\CompaniesBranches;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

class People extends Data
{
    public function __construct(
        public AppInterface $app,
        public CompaniesBranches $branch,
        public UserInterface $user,
        public string $firstname,
        #[DataCollectionOf(Contact::class)]
        public DataCollection $contacts,
        #[DataCollectionOf(Address::class)]
        public DataCollection $address,
        public ?string $lastname = null,
        public int $id = 0,
        #[WithCast(DateTimeInterfaceCast::class, format: 'Y-m-d')]
        public ?DateTime $dob = null,
        public ?string $license_number = null,
        public ?string $facebook_contact_id = null,
        public ?string $google_contact_id = null,
        public ?string $apple_contact_id = null,
        public ?string $linkedin_contact_id = null,
        public ?string $middlename = null,
        public array $custom_fields = [],
        public array $tags = [],
        public array $peopleEmploymentHistory = [],
        public ?string $organization = null,
        public ?string $created_at = null,
        public ?bool $flushPreviousAddress = false,
    ) {
        $this->cleanFirstNameFromMiddleName();
    }

    public function getEmails(): array
    {
        return $this->contacts->toCollection()
            ->filter(function (Contact $contact) {
                return $contact->getType() === 'email';
            })
            ->values()
            ->toArray();
    }

    public function getPhones(): array
    {
        return $this->contacts->toCollection()
            ->filter(function (Contact $contact) {
                return $contact->getType() === 'phone';
            })
            ->values()
            ->toArray();
    }

    public function getName(): string
    {
        return $this->firstname . ' ' . $this->lastname;
    }

    public function cleanFirstNameFromMiddleName(): void
    {
        if (empty($this->middlename)) {
            return;
        }

        $nameParts = array_filter(explode(' ', trim($this->firstname)));

        if (count($nameParts) <= 1) {
            return;
        }

        $lastPart = end($nameParts);

        // Case-insensitive comparison with trimming
        if (strcasecmp(trim($lastPart), trim($this->middlename)) === 0) {
            array_pop($nameParts);
            $this->firstname = implode(' ', $nameParts);
        }
    }

    public function flushPreviousAddress(): void
    {
        $this->flushPreviousAddress = true;
    }
}
