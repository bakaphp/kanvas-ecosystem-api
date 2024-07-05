<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Apollo\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Apollo\Actions\ScreeningAction;
use Kanvas\Guild\Customers\Actions\UpdatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address as DataTransferObjectAddress;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\ContactType;
use Kanvas\Guild\Customers\Models\PeopleEmploymentHistory;
use Kanvas\Guild\Organizations\Actions\CreateOrganizationAction;
use Kanvas\Guild\Organizations\DataTransferObject\Organization;
use Kanvas\Locations\Models\Countries;
use Kanvas\Locations\Models\States;
use Spatie\LaravelData\DataCollection;
use Workflow\Activity;

class ScreeningPeopleActivity extends Activity
{
    public $tries = 1;

    public function execute(Model $people, AppInterface $app, array $params): array
    {
        $peopleData = (new ScreeningAction($people, $app))->execute();
        $contacts = $this->buildContacts($peopleData);
        $address = $this->buildAddress($peopleData);

        $peopleDto = People::from([
            'app' => $app,
            'branch' => $people->company->defaultBranch,
            'user' => $people->user,
            'firstname' => $peopleData['first_name'],
            'middlename' => $people->middlename ?? null,
            'lastname' => $peopleData['last_name'] ?? $people->lastname,
            'contacts' => Contact::collect($contacts, DataCollection::class),
            'address' => DataTransferObjectAddress::collect($address, DataCollection::class),
            'id' => $people->getId(),
            'custom_fields' => [
                'headline' => $peopleData['headline'] ?? '',
                'title' => $peopleData['title'] ?? '',
            ],
        ]);

        (new UpdatePeopleAction($people, $peopleDto))->execute();
        $this->updateEmploymentHistory($people, $app, $peopleData['employment_history']);

        return [
            'status' => 'success',
            'message' => 'People screened successfully',
            'people_id' => $people->id,
        ];
    }

    private function buildContacts(array $peopleData): array
    {
        $linkedinId = ContactType::getByName('LinkedIn')->getId();
        $contacts = [
            [
                'contacts_types_id' => $linkedinId,
                'value' => $peopleData['linkedin_url'],
                'weight' => 0,
            ],
            [
                'contacts_types_id' => ContactTypeEnum::EMAIL->value,
                'value' => $peopleData['email'],
                'weight' => 1,
            ],
        ];

        if (! empty($peopleData['phone_numbers'][0])) {
            $contacts[] = [
                'contacts_types_id' => ContactTypeEnum::PHONE->value,
                'value' => $peopleData['phone_numbers'][0]['sanitized_number'],
                'weight' => 2,
            ];
        }

        return array_values(array_filter($contacts, fn ($contact) => ! empty($contact['value'])));
    }

    private function buildAddress(array $peopleData): array
    {
        if (empty($peopleData['country']) || empty($peopleData['state']) || empty($peopleData['city'])) {
            return [];
        }

        $state = States::getByName($peopleData['state']);
        $countryId = Countries::getByName($peopleData['country'])->getId() ?? Countries::getByName('United States')->getId();

        return [
            [
                'address' => '',
                'address_2' => '',
                'city' => $peopleData['city'],
                'state' => $state ? $state->code : null,
                'county' => '',
                'zip' => '',
                'city_id' => null,
                'state_id' => $state ? $state->id : null,
                'countries_id' => $countryId,
            ],
        ];
    }

    private function updateEmploymentHistory(Model $people, AppInterface $app, array $employmentHistory): void
    {
        foreach ($employmentHistory as $employment) {
            $organization = new CreateOrganizationAction(
                new Organization(
                    $people->company,
                    $people->user,
                    $app,
                    $employment['organization_name'],
                    $employment['raw_address']
                )
            );

            PeopleEmploymentHistory::firstOrCreate([
                'status' => (int)$employment['current'],
                'start_date' => $employment['start_date'],
                'end_date' => $employment['end_date'],
                'position' => $employment['title'],
                'apps_id' => $app->getId(),
                'peoples_id' => $people->id,
                'organizations_id' => $organization->execute()->getId(),
            ]);
        }
    }
}
