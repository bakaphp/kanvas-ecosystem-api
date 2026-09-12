<?php

declare(strict_types=1);

namespace Tests\Guild\Leads;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\LeadPullResult;
use Tests\TestCase;

final class LeadPullResultTest extends TestCase
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
        $result = LeadPullResult::for($this->createLead())->toArray();

        $this->assertSame(self::EXPECTED_KEYS, array_keys($result));
    }

    public function testMapsLeadAndPeopleFields(): void
    {
        $lead = $this->createLead();

        $result = LeadPullResult::for($lead)->toArray();

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

        $result = LeadPullResult::for($lead)->toArray();

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

        $result = LeadPullResult::for($lead->refresh())->toArray();

        $this->assertSame('', $result['status']);
    }

    public function testPhoneComesFromAllPhonesSoCellphoneIsIncluded(): void
    {
        $lead = $this->createLead();
        $lead->people->addCellPhone('2296466762');

        $result = LeadPullResult::for($lead->refresh())->toArray();

        $this->assertNotNull($result['phone']);
    }

    public function testRankDefaultsToOneAndIsOverridable(): void
    {
        $lead = $this->createLead();

        $this->assertSame(1.0, LeadPullResult::for($lead)->toArray()['rank']);
        $this->assertSame(0.67, LeadPullResult::for($lead, 0.67)->toArray()['rank']);
    }

    /**
     * Callers rank and sort candidates before serializing, so the lead and its
     * rank have to be readable off the value object itself — toArray() is the
     * wire boundary, not the only way in.
     */
    public function testExposesTheLeadAndRankWithoutSerializing(): void
    {
        $lead = $this->createLead();

        $result = LeadPullResult::for($lead, 0.5);

        $this->assertSame($lead->getId(), $result->lead->getId());
        $this->assertSame(0.5, $result->rank);
    }

    public function testRecentlyCreatedIsFalseForALeadReadBackFromTheDatabase(): void
    {
        $lead = $this->createLead();

        $result = LeadPullResult::for(Lead::getById($lead->id))->toArray();

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
