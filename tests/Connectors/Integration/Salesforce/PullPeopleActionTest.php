<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Salesforce;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Salesforce\Actions\PullOrganizationAction;
use Kanvas\Connectors\Salesforce\Actions\PullPeopleAction;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Tests\TestCase;

final class PullPeopleActionTest extends TestCase
{
    use DatabaseTransactions;

    public function testCreatesPeopleWhenNoMatchingCustomFieldExists(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();

        $people = new PullPeopleAction(
            $app,
            $company,
            ['FirstName' => 'John', 'LastName' => 'Appleseed', 'Email' => 'john@example.com'],
            '003xx000004TmiQAAS',
        )->execute();

        $this->assertSame('John', $people->firstname);
        $this->assertSame('Appleseed', $people->lastname);
        $this->assertSame('003xx000004TmiQAAS', $people->get(CustomFieldEnum::SALESFORCE_CONTACT_ID->value));
    }

    public function testDoesNotFabricateFirstnameWhenSalesforceOmitsIt(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();

        $people = new PullPeopleAction(
            $app,
            $company,
            ['LastName' => 'Abreu' . uniqid()],
            '003xx000004TmiQAAT',
        )->execute();

        $this->assertSame('', $people->firstname);
    }

    public function testUpdatesPeopleWhenMatchingCustomFieldExists(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();

        $existing = new PullPeopleAction(
            $app,
            $company,
            ['FirstName' => 'John', 'LastName' => 'Appleseed'],
            '003xx000004TmiQAAS',
        )->execute();

        $updated = new PullPeopleAction(
            $app,
            $company,
            ['FirstName' => 'John', 'LastName' => 'Appleseed Jr'],
            '003xx000004TmiQAAS',
        )->execute();

        $this->assertSame($existing->getId(), $updated->getId());
        $this->assertSame('Appleseed Jr', $updated->lastname);
    }

    public function testDoesNotMergeIntoAnUnrelatedPeopleSharingPhoneOrEmail(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();

        // Real-world case this guards: two distinct Salesforce Contacts (different Ids) that
        // happen to share a phone number. CreatePeopleAction::checkIfPeopleExist() would normally
        // fold the second one into the first — that's a duplicate for the merge/dedup flow to
        // catch, not something the pull itself should silently resolve.
        $first = new PullPeopleAction(
            $app,
            $company,
            ['FirstName' => 'Andres', 'LastName' => 'Pina', 'Email' => 'pina@pina.com', 'Phone' => '8299992211'],
            '003xx0000000001AAA',
        )->execute();

        $second = new PullPeopleAction(
            $app,
            $company,
            ['FirstName' => 'Arfenis', 'LastName' => 'Puello', 'Email' => 'arfen@mctekk.com', 'Phone' => '8299992211'],
            '003xx0000000002AAA',
        )->execute();

        $this->assertNotSame($first->getId(), $second->getId());
        $this->assertSame('Pina', $first->lastname);
        $this->assertSame('Puello', $second->lastname);
        $this->assertSame('003xx0000000001AAA', $first->get(CustomFieldEnum::SALESFORCE_CONTACT_ID->value));
        $this->assertSame('003xx0000000002AAA', $second->get(CustomFieldEnum::SALESFORCE_CONTACT_ID->value));
    }

    public function testLinksToOrganizationResolvedByAccountId(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();

        $organization = new PullOrganizationAction(
            $app,
            $company,
            ['Name' => 'Acme Corp'],
            '001xx000003DHP0AAA',
        )->execute();

        $people = new PullPeopleAction(
            $app,
            $company,
            ['FirstName' => 'John', 'LastName' => 'Appleseed', 'AccountId' => '001xx000003DHP0AAA'],
            '003xx000004TmiQAAS',
        )->execute();

        $this->assertTrue($organization->peoples()->where('peoples.id', $people->getId())->exists());
    }
}
