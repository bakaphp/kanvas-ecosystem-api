<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Salesforce;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Salesforce\Actions\PullPropertyAction;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Tests\Connectors\Traits\HasSalesforceConfiguration;
use Tests\TestCase;

final class PullPropertyActionTest extends TestCase
{
    use DatabaseTransactions;
    use HasSalesforceConfiguration;

    // `inventory` is left out on purpose: CreateProductAction retries its own deadlocks, and a test
    // transaction on that connection demotes it to a savepoint that cannot (tests/CLAUDE.md).
    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'crm'];

    private function pull(string $salesforceId): ?Variants
    {
        $user = static::$cachedUser;

        return new PullPropertyAction(
            app(Apps::class),
            $user->getCurrentCompany(),
            $user,
            $salesforceId
        )->execute();
    }

    private function fakeLocationQuery(array $records): void
    {
        $this->fakeSalesforceOAuth();
        Http::fake([
            self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/query*' => Http::response([
                'done' => true,
                'records' => $records,
            ], 200),
        ]);
    }

    public function testLocationBecomesAVariantOfItsProjectProduct(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();
        $this->configureSalesforce($company);

        $locationId = 'a00' . strtoupper(substr(md5(uniqid()), 0, 15));
        $projectId = 'a02' . strtoupper(substr(md5(uniqid()), 0, 15));

        $this->fakeLocationQuery([[
            'Id' => $locationId,
            'Name' => '1001 - Test Project',
            'Parent_Deal__c' => $projectId,
            'Parent_Deal__r' => ['Name' => 'Test Project'],
            'Property_Name__c' => 'Test Property One',
            'Deal_Status__c' => 'Marketing',
            'Marketing_Status__c' => 'Active',
            'Store__c' => 1001,
        ]]);

        $variant = $this->pull($locationId);

        $this->assertInstanceOf(Variants::class, $variant);
        $this->assertSame('sf-location-' . $locationId, $variant->sku);
        $this->assertSame('sf-project-' . $projectId, $variant->product->slug);
        $this->assertSame('Test Property One', $variant->name);
        $this->assertFalse(
            Products::fromApp($app)->fromCompany($company)->where('slug', 'sf-location-' . $locationId)->exists(),
            'a Location__c must never become its own standalone product',
        );
    }

    public function testSecondLocationOfTheSameProjectJoinsTheSameProduct(): void
    {
        $this->configureSalesforce(static::$cachedUser->getCurrentCompany());

        $projectId = 'a02' . strtoupper(substr(md5(uniqid()), 0, 15));
        $firstId = 'a00' . strtoupper(substr(md5(uniqid('a')), 0, 15));
        $secondId = 'a00' . strtoupper(substr(md5(uniqid('b')), 0, 15));

        $this->fakeSalesforceOAuth();
        Http::fake([
            self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/query*' => Http::sequence()
                ->push(['done' => true, 'records' => [[
                    'Id' => $firstId,
                    'Parent_Deal__c' => $projectId,
                    'Parent_Deal__r' => ['Name' => 'Test Project'],
                    'Property_Name__c' => 'Test Property One',
                ]]])
                ->push(['done' => true, 'records' => [[
                    'Id' => $secondId,
                    'Parent_Deal__c' => $projectId,
                    'Parent_Deal__r' => ['Name' => 'Test Project'],
                    'Property_Name__c' => 'Test Property Two',
                ]]]),
        ]);

        $first = $this->pull($firstId);
        $second = $this->pull($secondId);

        $this->assertSame($first->products_id, $second->products_id);
        $this->assertSame(
            2,
            Variants::where('products_id', $first->products_id)->where('sku', 'like', 'sf-location-%')->notDeleted()->count(),
        );
    }

    public function testReturnsNullWhenTheLocationIsGoneFromSalesforce(): void
    {
        $this->configureSalesforce(static::$cachedUser->getCurrentCompany());

        $this->fakeLocationQuery([]);

        $this->assertNull($this->pull('a00xx000001GONEAAA'));
    }
}
