<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Peoples;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Traits\HasMutationUploadFiles;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\Actions\UpdatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People;
use Kanvas\Guild\Customers\Models\People as ModelsPeople;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Spatie\LaravelData\DataCollection;

class PeopleManagementMutation
{
    use HasMutationUploadFiles;

    /**
     * Create new customer
     */
    public function create(mixed $root, array $req): ModelsPeople
    {
        $user = auth()->user();
        $data = $req['input'];

        $people = People::from([
            'app' => app(Apps::class),
            'branch' => $user->getCurrentBranch(),
            'user' => $user,
            'firstname' => $data['firstname'],
            'middlename' => $data['middlename'] ?? null,
            'lastname' => $data['lastname'] ?? null,
            'contacts' => Contact::collect($data['contacts'] ?? [], DataCollection::class),
            'address' => Address::collect($data['address'] ?? [], DataCollection::class),
            'id' => $data['id'] ?? 0,
            'dob' => $data['dob'] ?? null,
            'facebook_contact_id' => $data['facebook_contact_id'] ?? null,
            'google_contact_id' => $data['google_contact_id'] ?? null,
            'apple_contact_id' => $data['apple_contact_id'] ?? null,
            'linkedin_contact_id' => $data['linkedin_contact_id'] ?? null,
            'tags' => $data['tags'] ?? [],
            'custom_fields' => $data['custom_fields'] ?? [],
            'peopleEmploymentHistory' => $data['peopleEmploymentHistory'] ?? [],
            'organization' => $data['organization'] ?? null,
        ]);

        $createPeople = new CreatePeopleAction($people);

        return $createPeople->execute();
    }

    protected function getPeopleById(int $id, UserInterface $user, AppInterface $app, CompanyInterface $company): ModelsPeople
    {
        if (! $user->isAppOwner()) {
            return ModelsPeople::getByIdFromCompanyApp($id, $company, $app);
        }

        return PeoplesRepository::getById(
            id: $id,
            app: $app,
        );
    }

    public function update(mixed $root, array $req): ModelsPeople
    {
        $user = auth()->user();
        $data = $req['input'];
        $app = app(Apps::class);

        $people = $this->getPeopleById((int) $data['id'], $user, $app, $user->getCurrentCompany());

        $peopleData = People::from([
            'app' => app(Apps::class),
            'branch' => $user->getCurrentBranch(),
            'user' => $user,
            'firstname' => $data['firstname'],
            'middlename' => $data['middlename'] ?? null,
            'lastname' => $data['lastname'] ?? null,
            'contacts' => Contact::collect($data['contacts'] ?? [], DataCollection::class),
            'address' => Address::collect($data['address'] ?? [], DataCollection::class),
            'id' => $people->getId(),
            'dob' => $data['dob'] ?? null,
            'facebook_contact_id' => $data['facebook_contact_id'] ?? null,
            'google_contact_id' => $data['google_contact_id'] ?? null,
            'apple_contact_id' => $data['apple_contact_id'] ?? null,
            'linkedin_contact_id' => $data['linkedin_contact_id'] ?? null,
            'tags' => $data['tags'] ?? [],
            'custom_fields' => $data['custom_fields'] ?? [],
            'organization' => $data['organization'] ?? null,
        ]);

        $updatePeople = new UpdatePeopleAction($people, $peopleData);

        return $updatePeople->execute();
    }

    /**
     * @psalm-suppress MixedReturnStatement
     */
    public function delete(mixed $root, array $req): bool
    {
        $user = auth()->user();
        $app = app(Apps::class);

        $people = $this->getPeopleById((int) $req['id'], $user, $app, $user->getCurrentCompany());

        return $people->softDelete();
    }

    public function attachFile(mixed $root, array $req): ModelsPeople
    {
        $app = app(Apps::class);
        $user = auth()->user();

        $people = $this->getPeopleById((int) $req['id'], $user, $app, $user->getCurrentCompany());

        return $this->uploadFileToEntity(
            model: $people,
            app: $app,
            user: $user,
            request: $req
        );
    }

    /**
     * @psalm-suppress MixedReturnStatement
     */
    public function restore(mixed $root, array $req): bool
    {
        $user = auth()->user();
        $app = app(Apps::class);

        $people = $this->getPeopleById((int) $req['id'], $user, $app, $user->getCurrentCompany());

        return $people->restoreRecord();
    }
}
