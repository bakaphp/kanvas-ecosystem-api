<?php

declare(strict_types=1);

namespace Tests\Guild\Leads;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Factories\PeopleFactory;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Events\LeadUpdateEvent;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\TestCase;

final class LeadUpdateEventTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm'];

    public function testBroadcastPayloadIncludesPeopleName(): void
    {
        $people = $this->createPerson();
        $lead = $this->createLead($people);

        $payload = new LeadUpdateEvent($lead->fresh())->broadcastWith();

        $this->assertSame($people->name, $payload['people']['name']);
    }

    public function testBroadcastPayloadSurvivesSoftDeletedPeople(): void
    {
        $people = $this->createPerson();
        $lead = $this->createLead($people);

        $people->delete();

        $payload = new LeadUpdateEvent($lead->fresh())->broadcastWith();

        $this->assertSame($lead->getId(), $payload['id']);
        $this->assertNull($payload['people']['name']);
    }

    private function createPerson(): People
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var People $people */
        $people = PeopleFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create();

        return $people->fresh();
    }

    private function createLead(People $people): Lead
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        return Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->withUserId($user->getId())
            ->withPeopleId($people->getId())
            ->create();
    }
}
