<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Peoples;

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
        ]);

        $createPeople = new CreatePeopleAction($people);

        return $createPeople->execute();
    }

    public function update(mixed $root, array $req): ModelsPeople
    {
        $user = auth()->user();
        $data = $req['input'];

        $people = PeoplesRepository::getById((int) $req['id'], $user->getCurrentCompany());

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

        return PeoplesRepository::getById(
            (int) $req['id'],
            $user->getCurrentCompany()
        )->softDelete();
    }

    public function attachFileToPeople(mixed $root, array $request): ModelsPeople
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $people = ModelsPeople::getByIdFromCompanyApp((int) $request['people_id'], $user->getCurrentCompany(), $app);

        return $this->uploadFileToEntity(
            model: $people,
            app: $app,
            user: $user,
            request: $request
        );
    }

    /**
     * @psalm-suppress MixedReturnStatement
     */
    public function restore(mixed $root, array $req): bool
    {
        $user = auth()->user();

        return ModelsPeople::where('id', (int) $req['id'])
            ->where('companies_id', $user->getCurrentCompany()->getId())
            ->firstOrFail()->restoreRecord();
    }
}
