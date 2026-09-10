<?php

declare(strict_types=1);

namespace Tests\Guild\Leads;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\LeadPullResultService;
use Tests\TestCase;

final class LeadPullResultServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm'];

    private const array EXPECTED_KEYS = [
        'id',
        'uuid',
        'people_id',
        'firstname',
        'middlename',
        'lastname',
        'email',
        'phone',
        'status',
        'lead_type',
        'owner',
        'owner_id',
        'custom_fields',
        'rank',
        'recentlyCreated',
        'updated_at',
    ];

    /**
     * Every key is cross-repo contract surface — clients in jitsubai read this
     * array structurally. A missing key is a silent undefined on the other side,
     * so pin the exact set rather than spot-checking a few.
     */
    public function testEmitsExactlyTheContractKeys(): void
    {
        $result = LeadPullResultService::toArray($this->createLead());

        $this->assertSame(self::EXPECTED_KEYS, array_keys($result));
    }

    public function testMapsLeadAndPeopleFields(): void
    {
        $lead = $this->createLead();

        $result = LeadPullResultService::toArray($lead);

        $this->assertSame($lead->id, $result['id']);
        $this->assertSame($lead->uuid, $result['uuid']);
        $this->assertSame($lead->people->id, $result['people_id']);
        $this->assertSame($lead->people->firstname, $result['firstname']);
        $this->assertSame($lead->people->lastname, $result['lastname']);
        $this->assertSame($lead->leads_owner_id, $result['owner_id']);
        $this->assertIsArray($result['custom_fields']);
    }

    public function testStatusIsLowercased(): void
    {
        $lead = $this->createLead();

        $result = LeadPullResultService::toArray($lead);

        $this->assertSame(strtolower($result['status']), $result['status']);
    }

    /**
     * The TS types declare status as a non-nullable string and two client call
     * sites do .toLowerCase() on it, so a null here throws in the browser.
     */
    public function testStatusIsEmptyStringNeverNullWhenLeadHasNoStatus(): void
    {
        $lead = $this->createLead();
        $lead->leads_status_id = 0;
        $lead->saveOrFail();

        $result = LeadPullResultService::toArray($lead->refresh());

        $this->assertSame('', $result['status']);
    }

    public function testPhoneComesFromAllPhonesSoCellphoneIsIncluded(): void
    {
        $lead = $this->createLead();
        $lead->people->addCellPhone('2296466762');

        $result = LeadPullResultService::toArray($lead->refresh());

        $this->assertNotNull($result['phone']);
    }

    public function testRankDefaultsToOneAndIsOverridable(): void
    {
        $lead = $this->createLead();

        $this->assertSame(1.0, LeadPullResultService::toArray($lead)['rank']);
        $this->assertSame(0.67, LeadPullResultService::toArray($lead, 0.67)['rank']);
    }

    public function testRecentlyCreatedIsFalseForALeadReadBackFromTheDatabase(): void
    {
        $lead = $this->createLead();

        $result = LeadPullResultService::toArray(Lead::getById($lead->id));

        $this->assertFalse($result['recentlyCreated']);
    }

    private function createLead(): Lead
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $people = People::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withContacts(canUseFakeInfo: false)
            ->create();

        return Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();
    }
}
