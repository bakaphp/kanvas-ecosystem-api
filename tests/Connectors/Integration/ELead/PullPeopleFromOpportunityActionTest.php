<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\ELead;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Elead\Actions\PullPeopleFromOpportunityAction;
use Kanvas\Connectors\Elead\Entities\Lead as LeadEntity;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Connectors\Elead\Support\EleadCache;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\TestCase;

final class PullPeopleFromOpportunityActionTest extends TestCase
{
    public function testAnExistingLeadPullsEveryEleadPhoneAndKeepsKanvasOnlyContacts(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $number = '615' . random_int(2000000, 9999999);
        $kanvasOnlyNumber = '809' . random_int(2000000, 9999999);

        $people = People::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['firstname' => 'Kellen', 'lastname' => 'Alexander']);
        $people->addPhone($number, 0, 100);
        $people->addCellPhone($kanvasOnlyNumber);

        $lead = Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();

        $customerId = 'customer-' . uniqid();
        new EleadCache($app, $company)->set('customer', $customerId, [
            'id' => $customerId,
            'isBusiness' => false,
            'firstName' => 'Kellen',
            'lastName' => 'Alexander',
            'emails' => [['address' => 'kellen+' . uniqid() . '@kanvas.dev', 'emailType' => 'Personal']],
            'phones' => [
                ['number' => $number, 'phoneType' => 'Home'],
                ['number' => $number, 'phoneType' => 'Cellular'],
            ],
            'addresses' => [],
        ]);

        $opportunity = new LeadEntity();
        $opportunity->app = $app;
        $opportunity->company = $company;
        $opportunity->id = 'opp-' . uniqid();
        $opportunity->customerId = $customerId;

        $result = new PullPeopleFromOpportunityAction($lead, $opportunity)->execute();

        $this->assertNotNull($result);
        $this->assertSame($people->getId(), $result->getId());

        $phones = $people->contacts()
            ->whereIn('contacts_types_id', [ContactTypeEnum::PHONE->value, ContactTypeEnum::CELLPHONE->value])
            ->get()
            ->map(fn ($contact) => $contact->contacts_types_id . ':' . $contact->value)
            ->sort()
            ->values()
            ->all();

        $this->assertEqualsCanonicalizing([
            ContactTypeEnum::PHONE->value . ':' . $number,
            ContactTypeEnum::CELLPHONE->value . ':' . $number,
            ContactTypeEnum::CELLPHONE->value . ':' . $kanvasOnlyNumber,
        ], $phones);

        $this->assertSame($customerId, $people->get(CustomFieldEnum::CUSTOMER_ID->value));
    }

    public function testAnOpportunityWithoutACustomerIsANoOp(): void
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

        $lead = Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();

        $opportunity = new LeadEntity();
        $opportunity->app = $app;
        $opportunity->company = $company;

        $before = $people->contacts()->count();

        $this->assertNull(new PullPeopleFromOpportunityAction($lead, $opportunity)->execute());
        $this->assertSame($before, $people->contacts()->count());
    }
}
