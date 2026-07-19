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
