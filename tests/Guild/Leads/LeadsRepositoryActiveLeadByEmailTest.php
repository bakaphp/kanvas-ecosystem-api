<?php

declare(strict_types=1);

namespace Tests\Guild\Leads;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Tests\TestCase;

final class LeadsRepositoryActiveLeadByEmailTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm'];

    /**
     * Production case: 26 People shared one QA address and the inbox picked an old one whose leads
     * were all closed, so the email reached no lead and nothing answered it.
     */
    public function testFindsTheActiveLeadOfANewerPersonWhenAnOlderOneSharesTheAddress(): void
    {
        $email = 'shared-' . uniqid() . '@example.com';
        $this->createLead($this->createPerson($email), 'Complete');
        $activeLead = $this->createLead($this->createPerson($email), 'Active');

        $company = auth()->user()->getCurrentCompany();

        $this->assertSame(
            $activeLead->getId(),
            LeadsRepository::getActiveLeadByEmail($email, $company, app(Apps::class))?->getId()
        );
    }

    public function testPicksTheNewestActiveLeadAcrossPeople(): void
    {
        $email = 'shared-' . uniqid() . '@example.com';
        $this->createLead($this->createPerson($email), 'Active');
        $newest = $this->createLead($this->createPerson($email), 'Active');

        $company = auth()->user()->getCurrentCompany();

        $this->assertSame(
            $newest->getId(),
            LeadsRepository::getActiveLeadByEmail($email, $company, app(Apps::class))?->getId()
        );
    }

    public function testReturnsNullWhenNoPersonWithTheAddressHasAnActiveLead(): void
    {
        $email = 'closed-' . uniqid() . '@example.com';
        $this->createLead($this->createPerson($email), 'Complete');

        $company = auth()->user()->getCurrentCompany();

        $this->assertNull(LeadsRepository::getActiveLeadByEmail($email, $company, app(Apps::class)));
    }

    private function createPerson(string $email): People
    {
        $user = auth()->user();

        $people = People::factory()
            ->withUserId($user->getId())
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->create();

        $people->contacts()->create([
            'contacts_types_id' => ContactTypeEnum::EMAIL->value,
            'value' => $email,
            'weight' => 100,
        ]);

        return $people;
    }

    private function createLead(People $people, string $statusName): Lead
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $statusId = DB::connection('crm')->table('leads_status')->insertGetId([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'name' => $statusName,
            'is_default' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create(['leads_status_id' => $statusId]);
    }
}
