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
    /**
     * __construct.
     */
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompaniesBranches $branch,
        public readonly UserInterface $user,
        public readonly string $firstname,
        #[DataCollectionOf(Contact::class)]
        public readonly DataCollection $contacts,
        #[DataCollectionOf(Address::class)]
        public readonly DataCollection $address,
        public readonly ?string $lastname = null,
        public int $id = 0,
        #[WithCast(DateTimeInterfaceCast::class, format: 'Y-m-d')]
        public readonly ?DateTime $dob = null,
        public readonly ?string $facebook_contact_id = null,
        public readonly ?string $google_contact_id = null,
        public readonly ?string $apple_contact_id = null,
        public readonly ?string $linkedin_contact_id = null,
        public readonly ?string $middlename = null,
        public readonly array $custom_fields = [],
        public readonly array $tags = [],
        public readonly ?string $created_at = null
    ) {
    }
}
