<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Salesforce;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Salesforce\Actions\PullPropertyContactAction;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Tests\TestCase;

final class PullPropertyContactActionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'ecosystem', 'inventory'];

    private function freshVariant(Apps $app, Companies $company): Variants
    {
        $product = Products::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();

        return Variants::factory()->withAppId($app->getId())->withProductId($product->getId())->create();
    }

    public function testReusesTheSameBrokerAcrossTwoDifferentPropertyRelationships(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $variant = $this->freshVariant($app, $company);

        // Same real broker, two different Location_Contact__c rows — Salesforce creates one per
        // property a broker manages, so this is the exact shape that used to produce a duplicate.
        $first = new PullPropertyContactAction(
            $app,
            $company,
            $variant,
            ['Contact_Name__c' => 'Scott Young', 'Contact_Email__c' => 'scott.young@cbre.com'],
            'a0relationshipAAA',
        )->execute();

        $second = new PullPropertyContactAction(
            $app,
            $company,
            $variant,
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
        $variant = $this->freshVariant($app, $company);

        $first = new PullPropertyContactAction(
            $app,
            $company,
            $variant,
            ['Contact_Name__c' => 'Scott Young', 'Contact_Email__c' => 'scott.young@cbre.com'],
            'a0relationshipCCC',
        )->execute();

        $resynced = new PullPropertyContactAction(
            $app,
            $company,
            $variant,
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
        $variant = $this->freshVariant($app, $company);

        $first = new PullPropertyContactAction(
            $app,
            $company,
            $variant,
            ['Contact_Name__c' => 'No Email Broker'],
            'a0relationshipNoEmail',
        )->execute();

        $resynced = new PullPropertyContactAction(
            $app,
            $company,
            $variant,
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
        $companyB = Companies::factory()->create(['users_id' => $user->getId()]);
        $variantA = $this->freshVariant($app, $companyA);
        $variantB = $this->freshVariant($app, $companyB);

        $inCompanyA = new PullPropertyContactAction(
            $app,
            $companyA,
            $variantA,
            ['Contact_Name__c' => 'Shared Email Broker', 'Contact_Email__c' => 'shared.broker@example.com'],
            'a0relationshipCompanyA',
        )->execute();

        $inCompanyB = new PullPropertyContactAction(
            $app,
            $companyB,
            $variantB,
            ['Contact_Name__c' => 'Shared Email Broker', 'Contact_Email__c' => 'shared.broker@example.com'],
            'a0relationshipCompanyB',
        )->execute();

        $this->assertNotSame($inCompanyA->getId(), $inCompanyB->getId());
    }
}
