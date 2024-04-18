<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People;
use Kanvas\Guild\Organizations\DataTransferObject\Organization;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

class Lead extends Data
{
    /**
     * __construct.
     */
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompaniesBranches $branch,
        public readonly UserInterface $user,
        public readonly string $title,
        public readonly int $pipeline_stage_id,
        #[DataCollectionOf(People::class)]
        public readonly People $people,
        public readonly int $leads_owner_id = 0,
        public readonly int $type_id = 0,
        public readonly int $status_id = 0,
        public readonly int $source_id = 0,
        public readonly int $receiver_id = 0,
        public readonly ?string $description = null,
        public readonly ?string $reason_lost = null,
        public readonly Organization|null $organization = null,
        public readonly array $custom_fields = [],
        public readonly array $files = [],
    ) {
    }

    /**
     *  @psalm-suppress ArgumentTypeCoercion
     */
    public static function viaRequest(UserInterface $user, array $request): self
    {
        $branch = CompaniesBranches::getById($request['branch_id']);
        CompaniesRepository::userAssociatedToCompanyAndBranch(
            $branch->company,
            $branch,
            $user
        );

        return new self(
            app(Apps::class),
            $branch,
            $user,
            (string) $request['title'],
            (int) $request['pipeline_stage_id'],
            People::from([
                'app' => app(Apps::class),
                'branch' => $branch,
                'user' => $user,
                'firstname' => $request['people']['firstname'],
                'lastname' => $request['people']['lastname'],
                'contacts' => Contact::collect($request['people']['contacts'], DataCollection::class),
                'address' => Address::collect($request['people']['address'], DataCollection::class),
                'id' => $request['people']['id'] ?? 0,
                'dob' => $request['people']['dob'] ?? null,
                'facebook_contact_id' => $request['people']['facebook_contact_id'] ?? null,
                'google_contact_id' => $request['people']['google_contact_id'] ?? null,
                'apple_contact_id' => $request['people']['apple_contact_id'] ?? null,
                'linkedin_contact_id' => $request['people']['linkedin_contact_id'] ?? null,
            ]),
            (int) ($request['leads_owner_id'] ?? 0),
            (int) ($request['type_id'] ?? 0),
            (int) ($request['status_id'] ?? 0),
            (int) ($request['source_id'] ?? 0),
            (int) ($request['receiver_id'] ?? 0),
            $request['description'] ?? null,
            $request['reason_lost'] ?? null,
            isset($request['organization']) ? Organization::from([
                'company' => $branch->company,
                'user' => $user,
                ...$request['organization'],
            ]) : null,
            $request['custom_fields'] ?? [],
            $request['files'] ?? []
        );
    }
}
