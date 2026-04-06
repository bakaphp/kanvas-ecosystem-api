<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Peoples;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Traits\HasMutationUploadFiles;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\Actions\UpdatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People;
use Kanvas\Guild\Customers\Models\Address as ModelsAddress;
use Kanvas\Guild\Customers\Models\Contact as ModelsContact;
use Kanvas\Guild\Customers\Models\People as ModelsPeople;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Workflow\Enums\WorkflowEnum;
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
            'license_number' => $data['license_number'] ?? null,
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

        $people = $this->getPeopleById((int) $req['id'], $user, $app, $user->getCurrentCompany());

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
            'license_number' => $data['license_number'] ?? null,
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

        $peopleQuery = ModelsPeople::query()->where('id', (int) $req['id']);

        if (! $user->isAppOwner()) {
            $peopleQuery->where('companies_id', $user->getCurrentCompany()->getId());
        } else {
            $peopleQuery->where('apps_id', $app->getId());
        }

        return $peopleQuery->firstOrFail()->restoreRecord();
    }

    public function deletePeopleAddress(mixed $root, array $req): bool
    {
        $user = auth()->user();
        $app = app(Apps::class);

        $peopleAddress = ModelsAddress::getById((int) $req['id']);

        $people = $peopleAddress->people;

        if ($people->companies_id !== $user->getCurrentCompany()->getId() || $people->apps_id !== $app->getId()) {
            throw new Exception('You do not have permission to delete this address');
        }

        return $peopleAddress->delete();
    }

    public function updateContact(mixed $root, array $req): ModelsContact
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $input = $req['input'];

        $contact = ModelsContact::findOrFail((int) $req['id']);
        $people = $contact->people;

        if ($people->companies_id !== $user->getCurrentCompany()->getId() || $people->apps_id !== $app->getId()) {
            throw new Exception('You do not have permission to update this contact');
        }

        $contact->update([
            'value' => $input['value'] ?? $contact->value,
            'contacts_types_id' => $input['contacts_types_id'] ?? $contact->contacts_types_id,
            'weight' => $input['weight'] ?? $contact->weight,
            'is_opt_out' => $input['is_opt_out'] ?? $contact->is_opt_out,
        ]);

        $people->fireWorkflow(
            WorkflowEnum::UPDATED->value,
            true,
            [
                'app' => $people->app,
                'company' => $people->company,
            ]
        );

        return $contact->refresh();
    }

    public function deleteContact(mixed $root, array $req): bool
    {
        $user = auth()->user();
        $app = app(Apps::class);

        $contact = ModelsContact::findOrFail((int) $req['id']);
        $people = $contact->people;

        if ($people->companies_id !== $user->getCurrentCompany()->getId() || $people->apps_id !== $app->getId()) {
            throw new Exception('You do not have permission to delete this contact');
        }

        $deleted = $contact->delete();

        $people->fireWorkflow(
            WorkflowEnum::UPDATED->value,
            true,
            [
                'app' => $people->app,
                'company' => $people->company,
            ]
        );

        return $deleted;
    }

    public function updatePeoplePhoto(mixed $root, array $req): ModelsPeople
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        /** @var ModelsPeople $people */
        $people = ModelsPeople::getByIdFromCompanyApp((int) $req['id'], $company, $app);

        $this->uploadImageToEntity(
            $people,
            $app,
            $user,
            $req['file'],
            'photo'
        );

        return $people;
    }
}
