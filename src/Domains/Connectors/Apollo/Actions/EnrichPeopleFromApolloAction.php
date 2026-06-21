<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Apollo\Actions;

use Baka\Support\Str;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Apollo\Enums\ConfigurationEnum;
use Kanvas\Guild\Customers\Actions\UpdatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address as AddressData;
use Kanvas\Guild\Customers\DataTransferObject\Contact as ContactData;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleData;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\ContactType;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Models\PeopleEmploymentHistory;
use Kanvas\Guild\Organizations\Actions\CreateOrganizationAction;
use Kanvas\Guild\Organizations\DataTransferObject\Organization;
use Kanvas\Guild\Organizations\Models\OrganizationPeople;
use Kanvas\Locations\Models\Countries;
use Kanvas\Locations\Models\States;
use Spatie\LaravelData\DataCollection;

/**
 * Fetches a person's Apollo match and writes the enrichment back to the People
 * record (job, employment history, LinkedIn, email, location, organization).
 *
 * This is the runtime-agnostic core of the enrichment. ScreeningPeopleActivity
 * keeps the rate-limit / recently-screened gating and delegates the actual
 * fetch + write here, so the same logic can run directly from a command without
 * a workflow/activity in the loop.
 */
class EnrichPeopleFromApolloAction
{
    public function __construct(
        protected People $people,
        protected Apps $app
    ) {
    }

    public function execute(): array
    {
        try {
            $peopleData = new ScreeningAction($this->people, $this->app)->execute();
        } catch (GuzzleException $e) {
            return $this->response('failed', $e->getMessage());
        }

        if (empty($peopleData)) {
            return $this->response('failed', 'No Apollo match found');
        }

        $this->applyEnrichmentData($peopleData);

        return $this->response('success', 'People screened successfully', $peopleData);
    }

    /**
     * Write a raw Apollo `person` payload onto the People record. Public so the
     * write-back can be exercised deterministically without a live Apollo call.
     */
    public function applyEnrichmentData(array $peopleData): void
    {
        $contacts = $this->buildContacts($peopleData);
        $address = $this->buildAddress($peopleData);
        $peopleDto = $this->buildPeopleDto($peopleData, $contacts, $address);

        new UpdatePeopleAction($this->people, $peopleDto)->execute();

        if (! empty($peopleData['organization'])) {
            $this->setOrganization($peopleData['organization']);
        }

        $this->updateEmploymentHistory($peopleData['employment_history'] ?? []);
        $this->updateTodayReport(! empty($peopleData['employment_history']));
    }

    private function buildPeopleDto(array $peopleData, array $contacts, array $address): PeopleData
    {
        return PeopleData::from([
            'app' => $this->app,
            'branch' => $this->people->company->defaultBranch,
            'user' => $this->people->user,
            'firstname' => $peopleData['first_name'] ?? $this->people->firstname,
            'middlename' => $this->people->middlename ?? $this->people->middlename,
            'lastname' => $peopleData['last_name'] ?? $this->people->lastname,
            'contacts' => ContactData::collect($contacts, DataCollection::class),
            'address' => AddressData::collect($address, DataCollection::class),
            'id' => $this->people->getId(),
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

    private function updateTodayReport(bool $successExtraction): void
    {
        $company = $this->people->company;
        $todayReport = $this->getTodayReport();
        $today = date('Y-m-d');

        $todayReport[$today] = [
            'total' => $todayReport[$today]['total'] + 1 ?? 1,
            'success' => $successExtraction ? ($todayReport[$today]['success'] + 1 ?? 1) : ($todayReport[$today]['success'] ?? 0),
            'processed' => $todayReport[$today]['processed'] + 1 ?? 1,
            'failed' => ! $successExtraction ? ($todayReport[$today]['failed'] + 1 ?? 1) : ($todayReport[$today]['failed'] ?? 0),
        ];

        $company->set(ConfigurationEnum::APOLLO_COMPANY_REPORTS->value, $todayReport);
        $this->people->set(ConfigurationEnum::APOLLO_DATA_ENRICHMENT_CUSTOM_FIELDS->value, time());
    }

    private function setOrganization(array $organizationData): void
    {
        if (empty($organizationData['name'])) {
            return;
        }

        $organization = new CreateOrganizationAction(
            new Organization(
                $this->people->company,
                $this->people->user,
                $this->app,
                $organizationData['name'],
                $organizationData['email'] ?? null,
                $organizationData['sanitized_phone'] ?? null,
                $organizationData['raw_address'] ?? null,
                $organizationData['city'] ?? null
            )
        )->execute();

        OrganizationPeople::addPeopleToOrganization($organization, $this->people);

        $this->people->set('company', $organizationData['name']);

        if (! empty($organizationData['logo_url'])) {
            $organization->set('logo', $organizationData['logo_url']);
        }

        if (! empty($organizationData['linkedin_url'])) {
            $organization->set('linkedin_url', $organizationData['linkedin_url']);
        }

        if (! empty($organizationData['sanitized_phone'])) {
            $organization->set('phone', $organizationData['sanitized_phone']);
        }

        if (! empty($organizationData['short_description'])) {
            $organization->set('short_description', $organizationData['short_description']);
        }

        if (! empty($organizationData['raw_address'])) {
            $organization->address = $organizationData['raw_address'];
            $organization->set('country', $organizationData['country']);
            $organization->save();
        }

        if (! empty($organizationData['keywords'])) {
            $organization->addTags($organizationData['keywords']);
        }
    }

    private function updateEmploymentHistory(array $employmentHistory): void
    {
        foreach ($employmentHistory as $employment) {
            if (empty($employment['organization_name'])) {
                continue;
            }

            $organization = new CreateOrganizationAction(
                new Organization(
                    $this->people->company,
                    $this->people->user,
                    $this->app,
                    $employment['organization_name'],
                    $employment['raw_address'] ?? null
                )
            )->execute();

            if (empty($employment['title'])) {
                continue;
            }

            PeopleEmploymentHistory::firstOrCreate([
                'status' => (int) $employment['current'],
                'start_date' => $employment['start_date'],
                'end_date' => $employment['end_date'],
                'position' => $employment['title'],
                'apps_id' => $this->app->getId(),
                'peoples_id' => $this->people->id,
                'organizations_id' => $organization->getId(),
            ]);

            if ((int) $employment['current'] === 1) {
                $this->people->set('company', $employment['organization_name']);
            }

            $this->assignAudienceSegment($employment['title']);
        }
    }

    private function assignAudienceSegment(string $jobTitle): void
    {
        $segments = $this->app->get(ConfigurationEnum::APOLLO_JOB_SEGMENTS->value);
        if (empty($segments)) {
            return;
        }

        $this->people->addTags($this->getMatchingSegments($segments, $jobTitle));
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
            $this->createContact($linkedinId, $peopleData['linkedin_url'] ?? null, 0),
            $this->createContact(ContactTypeEnum::EMAIL->value, $peopleData['email'] ?? null, 1),
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

    private function getTodayReport(): array
    {
        $report = $this->people->company->get(ConfigurationEnum::APOLLO_COMPANY_REPORTS->value) ?? [];

        if (! isset($report[date('Y-m-d')])) {
            $report[date('Y-m-d')] = ['total' => 0, 'success' => 0, 'processed' => 0, 'failed' => 0];
        }

        return $report;
    }

    private function response(string $status, string $message, array $data = []): array
    {
        return [
            'status' => $status,
            'message' => $message,
            'people_id' => $this->people->id,
            'data' => $data,
        ];
    }
}
