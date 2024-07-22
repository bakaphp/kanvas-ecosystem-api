<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
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
        public readonly bool $runWorkflow = true
    ) {
    }

    /**
     *  @psalm-suppress ArgumentTypeCoercion
     */
    public static function viaRequest(UserInterface $user, AppInterface $app, array $request): self
    {
        $branch = isset($request['branch_id']) ? CompaniesBranches::getById($request['branch_id']) : $user->getCurrentCompany()->branch;
        CompaniesRepository::userAssociatedToCompanyAndBranch(
            $branch->company,
            $branch,
            $user
        );

        $firstname = $request['people']['firstname'] ?? '';
        $lastname = $request['people']['lastname'] ?? '';
        $title = $request['title'] ?? $firstname . ' ' . $lastname;

        return new self(
            $app,
            $branch,
            $user,
            (string) $title,
            (int) ($request['pipeline_stage_id'] ?? 0),
            People::from([
                'app' => $app,
                'branch' => $branch,
                'user' => $user,
                'firstname' => $firstname,
                'lastname' => $lastname,
                'contacts' => Contact::collect($request['people']['contacts'], DataCollection::class),
                'address' => Address::collect($request['people']['address'] ?? [], DataCollection::class),
                'id' => $request['people']['id'] ?? 0,
                'dob' => $request['people']['dob'] ?? null,
                'facebook_contact_id' => $request['people']['facebook_contact_id'] ?? null,
                'google_contact_id' => $request['people']['google_contact_id'] ?? null,
                'apple_contact_id' => $request['people']['apple_contact_id'] ?? null,
                'linkedin_contact_id' => $request['people']['linkedin_contact_id'] ?? null,
                'custom_fields' => $request['people']['custom_fields'] ?? [],
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
                'app' => $app,
                ...$request['organization'],
            ]) : null,
            $request['custom_fields'] ?? [],
            $request['files'] ?? []
        );
    }
}
