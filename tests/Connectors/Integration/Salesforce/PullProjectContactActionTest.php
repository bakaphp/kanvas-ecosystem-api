<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Salesforce;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Salesforce\Actions\PullProjectContactAction;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Inventory\Products\Models\Products;
use Tests\TestCase;

final class PullProjectContactActionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'ecosystem', 'inventory'];

    private function freshProduct(Apps $app, Companies $company): Products
    {
        return Products::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();
    }

    public function testCreatesAPersonTaggedWithTheRoleAndProduct(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $product = $this->freshProduct($app, $company);

        $people = new PullProjectContactAction(
            $app,
            $company,
            $product,
            [
                'First_Name__c' => 'Diana',
                'Last_Name__c' => 'Torres',
                'Email__c' => 'diana.torres@example.com',
                'Relationship__c' => 'Attorney',
            ],
            'a0projectContactAAA',
        )->execute();

        $this->assertNotNull($people);
        $this->assertSame(
            'a0projectContactAAA',
            $people->get(CustomFieldEnum::SALESFORCE_PROJECT_CONTACT_ID->value),
        );
        $this->assertContains(
            "Attorney - {$product->name} #Products:{$product->getId()}",
            $people->tags->pluck('name')->all(),
        );
    }

    public function testResyncingTheSameContactFindsTheSamePersonByEmail(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $product = $this->freshProduct($app, $company);

        $payload = [
            'First_Name__c' => 'Mario',
            'Last_Name__c' => 'Reyes',
            'Email__c' => 'mario.reyes@example.com',
            'Relationship__c' => 'Lender',
        ];

        $first = new PullProjectContactAction($app, $company, $product, $payload, 'a0projectContactBBB')->execute();
        $resynced = new PullProjectContactAction($app, $company, $product, $payload, 'a0projectContactBBB')->execute();

        $this->assertSame($first->getId(), $resynced->getId());
    }

    public function testReturnsNullWhenNeitherNameIsPresent(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $product = $this->freshProduct($app, $company);

        $people = new PullProjectContactAction(
            $app,
            $company,
            $product,
            ['Email__c' => 'nameless@example.com'],
            'a0projectContactNoName',
        )->execute();

        $this->assertNull($people);
    }
}
