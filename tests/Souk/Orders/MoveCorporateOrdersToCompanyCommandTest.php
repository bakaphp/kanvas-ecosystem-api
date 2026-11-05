<?php

declare(strict_types=1);

namespace Tests\Souk\Orders;

use App\Console\Commands\Souk\MoveCorporateOrdersToCompanyCommand;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Souk\Orders\Actions\AssignCorporateOrderOwnerAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class MoveCorporateOrdersToCompanyCommandTest extends TestCase
{
    private Apps $kanvasApp;
    private Users $systemUser;
    private Companies $mainCompany;
    private Companies $corporateCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $this->systemUser = $user;
        $this->mainCompany = $user->getCurrentCompany();

        $this->corporateCompany = Companies::factory()->create(['users_id' => $user->getId()]);
        $this->corporateCompany->associateApp($this->kanvasApp);
    }

    public function testDryRunWritesNothing(): void
    {
        $order = $this->makeOrderIn($this->corporateCompany, ['data' => ['user_company_id' => (string) $this->corporateCompany->getId()]]);

        $this->artisan('kanvas-souk:move-corporate-orders-to-company', $this->commandOptions($order))->assertSuccessful();

        $order->refresh();
        $this->assertSame($this->corporateCompany->getId(), (int) $order->companies_id);
        $this->assertArrayNotHasKey(MoveCorporateOrdersToCompanyCommand::ORIGIN_METADATA_KEY, $order->metadata['data']);
    }

    public function testMovesTheOrderToTheTargetCompanyKeepingTheCorporateCompanyAsProvider(): void
    {
        $order = $this->makeOrderIn($this->corporateCompany, ['user_company_id' => (string) $this->corporateCompany->getId(), 'data' => []]);
        $orderNumber = $order->order_number;

        $this->artisan('kanvas-souk:move-corporate-orders-to-company', [...$this->commandOptions($order), '--apply' => true])->assertSuccessful();

        $order->refresh();
        $this->assertSame($this->mainCompany->getId(), (int) $order->companies_id);
        $this->assertSame($orderNumber, $order->order_number);
        $this->assertSame(
            $this->corporateCompany->getId(),
            (int) $order->metadata['data'][MoveCorporateOrdersToCompanyCommand::ORIGIN_METADATA_KEY]
        );
        $this->assertTrue($order->providerCompanies()->where('companies.id', $this->corporateCompany->getId())->exists());
        $this->assertTrue($order->hasTag([AssignCorporateOrderOwnerAction::CORPORATE_TAG]));
    }

    public function testGivesTheOrderANewNumberWhenTheTargetCompanyAlreadyUsesIt(): void
    {
        $order = $this->makeOrderIn($this->corporateCompany, ['data' => ['user_company_id' => (string) $this->corporateCompany->getId()]]);
        $taken = $this->makeOrderIn($this->mainCompany, []);
        $taken->order_number = $order->order_number;
        $taken->saveQuietly();

        $this->artisan('kanvas-souk:move-corporate-orders-to-company', [...$this->commandOptions($order), '--apply' => true])->assertSuccessful();

        $order->refresh();
        $this->assertSame($this->mainCompany->getId(), (int) $order->companies_id);
        $this->assertNotSame($taken->order_number, $order->order_number);
    }

    public function testLeavesOrdersWithoutCorporateMetadataAlone(): void
    {
        $order = $this->makeOrderIn($this->corporateCompany, []);

        $this->artisan('kanvas-souk:move-corporate-orders-to-company', [...$this->commandOptions($order), '--apply' => true])->assertSuccessful();

        $this->assertSame($this->corporateCompany->getId(), (int) $order->refresh()->companies_id);
    }

    private function commandOptions(Order $order): array
    {
        return [
            'app_id' => $this->kanvasApp->getId(),
            '--to-company-id' => $this->mainCompany->getId(),
            '--order-id' => [$order->getId()],
        ];
    }

    private function makeOrderIn(Companies $company, array $metadata): Order
    {
        $people = People::factory()
            ->withUserId($this->systemUser->getId())
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($company->getId())
            ->create();

        return Order::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($company->getId())
            ->withUserId($this->systemUser->getId())
            ->withPeopleId($people->getId())
            ->create(['metadata' => $metadata])
            ->refresh();
    }
}
