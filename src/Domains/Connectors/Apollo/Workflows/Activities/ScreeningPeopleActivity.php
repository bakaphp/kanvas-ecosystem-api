<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Apollo\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Apollo\Actions\ScreeningAction;
use Kanvas\Connectors\Apollo\Enums\ConfigurationEnum;
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
    public $tries = 20;

    public function execute(Model $people, AppInterface $app, array $params): array
    {
        if ($this->hasReachedLimit($people)) {
            return $this->limitReachedResponse($people);
        }

        if ($this->hasBeenScreenedRecently($people)) {
            return $this->alreadyScreenedResponse($people);
        }

        $peopleData = (new ScreeningAction($people, $app))->execute();
        $this->processPeopleData($people, $app, $peopleData);

        return $this->successResponse($people, $peopleData);
    }

    private function hasReachedLimit(Model $people): bool
    {
        $todayReport = $this->getTodayReport($people);

        return $todayReport[date('Y-m-d')]['total'] >= 2000;
    }

    private function hasBeenScreenedRecently(Model $people): bool
    {
        $key = ConfigurationEnum::APOLLO_DATA_ENRICHMENT_CUSTOM_FIELDS->value;
        $apolloRevalidationThreshold = $people->company->get(ConfigurationEnum::APOLLO_REVALIDATION->value) ?? '-2 months';

        return $people->get($key) && $people->get($key) > strtotime($apolloRevalidationThreshold);
    }

    private function processPeopleData(Model $people, AppInterface $app, array $peopleData): void
    {
        $contacts = $this->buildContacts($peopleData);
        $address = $this->buildAddress($peopleData);
        $peopleDto = $this->buildPeopleDto($people, $app, $peopleData, $contacts, $address);

        (new UpdatePeopleAction($people, $peopleDto))->execute();
        $this->updateEmploymentHistory($people, $app, $peopleData['employment_history']);
        $this->updateTodayReport($people, ! empty($peopleData['employment_history']));
    }

    private function buildPeopleDto(Model $people, AppInterface $app, array $peopleData, array $contacts, array $address): People
    {
        return People::from([
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
            'location' => [
                'city' => $address[0]['city'] ?? null,
                'state' => $address[0]['state'] ?? null,
                'country' => $address[0]['countries_id'] ?? null,
            ],
        ]);
    }

    private function updateTodayReport(Model $people, bool $successExtraction): void
    {
        $company = $people->company;
        $todayReport = $this->getTodayReport($people);
        $today = date('Y-m-d');

        $todayReport[$today] = [
            'total' => $todayReport[$today]['total'] + 1 ?? 1,
            'success' => $successExtraction ? ($todayReport[$today]['success'] + 1 ?? 1) : ($todayReport[$today]['success'] ?? 0),
            'processed' => $todayReport[$today]['processed'] + 1 ?? 1,
            'failed' => ! $successExtraction ? ($todayReport[$today]['failed'] + 1 ?? 1) : ($todayReport[$today]['failed'] ?? 0),
        ];

        $company->set(ConfigurationEnum::APOLLO_COMPANY_REPORTS->value, $todayReport);
        $people->set(ConfigurationEnum::APOLLO_DATA_ENRICHMENT_CUSTOM_FIELDS->value, time());
    }

    private function updateEmploymentHistory(Model $people, AppInterface $app, array $employmentHistory): void
    {
        foreach ($employmentHistory as $employment) {
            if (empty($employment['organization_name'])) {
                continue;
            }

            $organization = (new CreateOrganizationAction(
                new Organization(
                    $people->company,
                    $people->user,
                    $app,
                    $employment['organization_name'],
                    $employment['raw_address']
                )
            ))->execute();

            PeopleEmploymentHistory::firstOrCreate([
                'status' => (int)$employment['current'],
                'start_date' => $employment['start_date'],
                'end_date' => $employment['end_date'],
                'position' => $employment['title'],
                'apps_id' => $app->getId(),
                'peoples_id' => $people->id,
                'organizations_id' => $organization->getId(),
            ]);

            $this->assignAudienceSegment($people, $app, $employment['title']);
        }
    }

    private function assignAudienceSegment(Model $people, AppInterface $app, string $jobTitle): void
    {
        $segments = $app->get(ConfigurationEnum::APOLLO_JOB_SEGMENTS->value);
        if (empty($segments)) {
            return;
        }

        $tags = $this->getMatchingSegments($segments, $jobTitle);
        $people->addTags($tags);
    }

    private function getMatchingSegments(array $segments, string $jobTitle): array
    {
        $jobTitle = strtolower($jobTitle);
        $tags = [];

        foreach ($segments as $segment => $data) {
            foreach ($data['keywords'] as $keyword) {
                if (Str::contains($jobTitle, strtolower($keyword))) {
                    $tags[strtolower($segment)] = strtolower($segment);
                }
            }
        }

        return $tags;
    }

    private function buildContacts(array $peopleData): array
    {
        $linkedinId = ContactType::getByName('LinkedIn')->getId();
        $contacts = [
            $this->createContact($linkedinId, $peopleData['linkedin_url'], 0),
            $this->createContact(ContactTypeEnum::EMAIL->value, $peopleData['email'], 1),
        ];

        if (! empty($peopleData['phone_numbers'][0])) {
            $contacts[] = $this->createContact(ContactTypeEnum::PHONE->value, $peopleData['phone_numbers'][0]['sanitized_number'], 2);
        }

        return $this->filterEmptyContacts($contacts);
    }

    private function createContact(int $typeId, ?string $value, int $weight): array
    {
        return [
            'contacts_types_id' => $typeId,
            'value' => $value,
            'weight' => $weight,
        ];
    }

    private function filterEmptyContacts(array $contacts): array
    {
        return array_values(array_filter($contacts, fn ($contact) => ! empty($contact['value'])));
    }

    private function buildAddress(array $peopleData): array
    {
        if (empty($peopleData['country']) || empty($peopleData['state']) || empty($peopleData['city'])) {
            return [];
        }

        try {
            $state = States::getByName($peopleData['state']);
            $country = Countries::getByName($peopleData['country']);

            return [
                $this->createAddress($peopleData, $state, $country),
            ];
        } catch (Exception $e) {
            return [];
        }
    }

    private function createAddress(array $peopleData, ?States $state, Countries $country): array
    {
        return [
            'address' => '',
            'address_2' => '',
            'city' => $peopleData['city'],
            'state' => $state?->code,
            'county' => '',
            'zip' => '',
            'city_id' => null,
            'state_id' => $state?->id,
            'countries_id' => $country->getId(),
            'country' => $country->name,
        ];
    }

    private function getTodayReport(Model $people): array
    {
        $report = $people->company->get(ConfigurationEnum::APOLLO_COMPANY_REPORTS->value) ?? [];

        if (! isset($report[date('Y-m-d')])) {
            $report[date('Y-m-d')] = ['total' => 0, 'success' => 0, 'processed' => 0, 'failed' => 0];
        }

        return $report;
    }

    private function limitReachedResponse(Model $people): array
    {
        return [
            'status' => 'failed',
            'message' => 'Limit reached',
            'people_id' => $people->id,
            'data' => [],
        ];
    }

    private function alreadyScreenedResponse(Model $people): array
    {
        return [
            'status' => 'success',
            'message' => 'People already screened',
            'people_id' => $people->id,
            'data' => [],
        ];
    }

    private function successResponse(Model $people, array $peopleData): array
    {
        return [
            'status' => 'success',
            'message' => 'People screened successfully',
            'people_id' => $people->id,
            'data' => $peopleData,
        ];
    }
}
