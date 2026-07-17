<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Salesforce;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Salesforce\Actions\PullDealAction;
use Kanvas\Connectors\Salesforce\Actions\PullOrganizationAction;
use Kanvas\Connectors\Salesforce\Actions\PullPeopleAction;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Tests\TestCase;

final class PullDealActionTest extends TestCase
{
    use DatabaseTransactions;

    public function testCreatesDealWhenNoMatchingCustomFieldExists(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();

        $deal = new PullDealAction(
            $app,
            $company,
            ['Name' => 'Big Opportunity', 'Description' => 'Closing soon'],
            '006xx000004TmXtAAK',
        )->execute();

        $this->assertSame('Big Opportunity', $deal->title);
        $this->assertSame('Closing soon', $deal->description);
        $this->assertSame('006xx000004TmXtAAK', $deal->get(CustomFieldEnum::SALESFORCE_OPPORTUNITY_ID->value));
    }

    public function testUpdatesDealWhenMatchingCustomFieldExists(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();

        $existing = new PullDealAction(
            $app,
            $company,
            ['Name' => 'Big Opportunity'],
            '006xx000004TmXtAAK',
        )->execute();

        $updated = new PullDealAction(
            $app,
            $company,
            ['Name' => 'Big Opportunity Renamed'],
            '006xx000004TmXtAAK',
        )->execute();

        $this->assertSame($existing->getId(), $updated->getId());
        $this->assertSame('Big Opportunity Renamed', $updated->title);
    }

    public function testLinksOrganizationAndPeopleResolvedFromPayload(): void
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
            ['FirstName' => 'John', 'LastName' => 'Appleseed'],
            '003xx000004TmiQAAS',
        )->execute();

        $deal = new PullDealAction(
            $app,
            $company,
            [
                'Name' => 'Big Opportunity',
                'AccountId' => '001xx000003DHP0AAA',
                'ContactId' => '003xx000004TmiQAAS',
            ],
            '006xx000004TmXtAAK',
        )->execute();

        $this->assertSame($organization->getId(), $deal->organization_id);
        $this->assertSame($people->getId(), $deal->people_id);
    }
}
