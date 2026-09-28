<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Salesforce;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Salesforce\Actions\PullPropertyContactAction;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Inventory\Products\Models\Products;
use Tests\TestCase;

final class PullPropertyContactActionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'ecosystem'];

    public function testReusesTheSameBrokerAcrossTwoDifferentPropertyRelationships(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $product = Products::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();

        // Same real broker, two different Location_Contact__c rows — Salesforce creates one per
        // property a broker manages, so this is the exact shape that used to produce a duplicate.
        $first = new PullPropertyContactAction(
            $app,
            $company,
            $product,
            ['Contact_Name__c' => 'Scott Young', 'Contact_Email__c' => 'scott.young@cbre.com'],
            'a0relationshipAAA',
        )->execute();

        $second = new PullPropertyContactAction(
            $app,
            $company,
            $product,
            ['Contact_Name__c' => 'Scott Young', 'Contact_Email__c' => 'scott.young@cbre.com'],
            'a0relationshipBBB',
        )->execute();

        $this->assertSame($first->getId(), $second->getId());
    }

    public function testResyncingTheSamePropertyRelationshipFindsTheSameBroker(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $product = Products::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();

        $first = new PullPropertyContactAction(
            $app,
            $company,
            $product,
            ['Contact_Name__c' => 'Scott Young', 'Contact_Email__c' => 'scott.young@cbre.com'],
            'a0relationshipCCC',
        )->execute();

        $resynced = new PullPropertyContactAction(
            $app,
            $company,
            $product,
            ['Contact_Name__c' => 'Scott Young', 'Contact_Email__c' => 'scott.young@cbre.com'],
            'a0relationshipCCC',
        )->execute();

        $this->assertSame($first->getId(), $resynced->getId());
    }

    public function testFallsBackToTheRelationshipIdWhenTheBrokerHasNoEmail(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $product = Products::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();

        $first = new PullPropertyContactAction(
            $app,
            $company,
            $product,
            ['Contact_Name__c' => 'No Email Broker'],
            'a0relationshipNoEmail',
        )->execute();

        $resynced = new PullPropertyContactAction(
            $app,
            $company,
            $product,
            ['Contact_Name__c' => 'No Email Broker'],
            'a0relationshipNoEmail',
        )->execute();

        $this->assertSame($first->getId(), $resynced->getId());
        $this->assertSame(
            'a0relationshipNoEmail',
            $resynced->get(CustomFieldEnum::SALESFORCE_LOCATION_CONTACT_ID->value),
        );
    }

    public function testDoesNotMergeIntoAnUnrelatedBrokerInADifferentCompany(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $companyA = $user->getCurrentCompany();
        $companyB = \Kanvas\Companies\Models\Companies::factory()->create(['users_id' => $user->getId()]);
        $productA = Products::factory()->withAppId($app->getId())->withCompanyId($companyA->getId())->create();
        $productB = Products::factory()->withAppId($app->getId())->withCompanyId($companyB->getId())->create();

        $inCompanyA = new PullPropertyContactAction(
            $app,
            $companyA,
            $productA,
            ['Contact_Name__c' => 'Shared Email Broker', 'Contact_Email__c' => 'shared.broker@example.com'],
            'a0relationshipCompanyA',
        )->execute();

        $inCompanyB = new PullPropertyContactAction(
            $app,
            $companyB,
            $productB,
            ['Contact_Name__c' => 'Shared Email Broker', 'Contact_Email__c' => 'shared.broker@example.com'],
            'a0relationshipCompanyB',
        )->execute();

        $this->assertNotSame($inCompanyA->getId(), $inCompanyB->getId());
    }
}
