<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Jobs\CreateLeadsFromReceiverJob;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Tests\TestCase;

class ProductLeadReceiversTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'inventory', 'crm', 'workflow'];

    public function testProductListsOnlyItsCompanyReceivers(): void
    {
        $app = app(Apps::class);
        $product = Products::factory()->create();
        $otherProduct = Products::factory()->create();

        $mine = $this->createReceiver($app, $product->company, 'tradeIn');
        $this->createReceiver($app, $otherProduct->company, 'contact');
        $deleted = $this->createReceiver($app, $product->company, 'offers');
        $deleted->softDelete();

        $this->assertSame([$mine->getId()], $product->leadReceivers()->pluck('id')->all());
    }

    public function testProductWithoutReceiversReturnsEmptyList(): void
    {
        $product = $this->createProductForCurrentCompany();

        // The dev DB seeds receivers on the test company; hide them inside the rolled-back transaction.
        LeadReceiver::query()
            ->where('companies_id', $product->companies_id)
            ->where('apps_id', $product->apps_id)
            ->update(['is_deleted' => 1]);

        $this->queryProduct($product, 'leadReceivers { id }')
            ->assertSuccessful()
            ->assertJsonPath('data.products.data.0.leadReceivers', []);
    }

    public function testVariantResolvesFromItsOwnCompany(): void
    {
        $app = app(Apps::class);
        $product = Products::factory()->create();
        $variant = $product->variants()->firstOrFail();

        $receiver = $this->createReceiver($app, $product->company, 'contact');
        $this->assertSame([$receiver->getId()], $variant->leadReceivers()->pluck('id')->all());

        $foreignCompany = Companies::factory()->create();
        $foreignReceiver = $this->createReceiver($app, $foreignCompany, 'finance');
        $variant->companies_id = $foreignCompany->getId();
        $variant->saveQuietly();

        $this->assertSame(
            [$foreignReceiver->getId()],
            Variants::find($variant->getId())->leadReceivers()->pluck('id')->all(),
        );
    }

    public function testEagerLoadKeepsEachProductOnItsOwnApp(): void
    {
        $app = app(Apps::class);
        $otherApp = $this->createBareApp();
        $company = Companies::factory()->create();

        $product = Products::factory()->withCompanyId($company->getId())->create();
        $otherAppProduct = Products::factory()
            ->withCompanyId($company->getId())
            ->withAppId($otherApp->getId())
            ->create();

        $receiver = $this->createReceiver($app, $company, 'contact');
        $otherAppReceiver = $this->createReceiver($otherApp, $company, 'contact');

        $loaded = Products::query()
            ->whereIn('id', [$product->getId(), $otherAppProduct->getId()])
            ->with('leadReceivers')
            ->get()
            ->keyBy('id');

        $this->assertSame([$receiver->getId()], $loaded[$product->getId()]->leadReceivers->pluck('id')->all());
        $this->assertSame(
            [$otherAppReceiver->getId()],
            $loaded[$otherAppProduct->getId()]->leadReceivers->pluck('id')->all(),
        );
    }

    public function testReceiverUuidIsThePostableWebhookUuid(): void
    {
        $product = $this->createProductForCurrentCompany();
        $receiver = $this->createReceiver(app(Apps::class), $product->company, 'tradeIn');

        $uuid = collect($this->queryProduct($product, 'leadReceivers { id uuid }')
            ->assertSuccessful()
            ->json('data.products.data.0.leadReceivers'))
            ->firstWhere('id', (string) $receiver->getId())['uuid'];

        $webhook = ReceiverWebhook::query()->where('uuid', $uuid)->notDeleted()->firstOrFail();

        $this->assertSame($receiver->uuid, $uuid);
        $this->assertTrue($webhook->is_active);
        $this->assertSame(CreateLeadsFromReceiverJob::class, $webhook->action->model_name);
    }

    private function createProductForCurrentCompany(): Products
    {
        return Products::factory()
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create();
    }

    private function queryProduct(Products $product, string $selection)
    {
        return $this->graphQL('
            query ($where: QueryProductsWhereWhereConditions) {
                products(where: $where) {
                    data { id ' . $selection . ' }
                }
            }
        ', ['where' => ['column' => 'ID', 'operator' => 'EQ', 'value' => $product->getId()]]);
    }

    private function createReceiver(Apps $app, Companies $company, string $name): LeadReceiver
    {
        return LeadReceiver::create([
            'name' => $name,
            'source_name' => $name,
            'users_id' => $company->users_id,
            'agents_id' => $company->users_id,
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
            'leads_sources_id' => 0,
            'lead_types_id' => 0,
            'is_default' => false,
        ]);
    }

    private function createBareApp(): Apps
    {
        $uniqueId = uniqid();
        $app = new Apps();
        $app->name = 'Other App ' . $uniqueId;
        $app->url = 'https://other-' . $uniqueId . '.example.com';
        $app->domain = 'other-' . $uniqueId . '.example.com';
        $app->description = 'Cross-tenant forms test app';
        $app->is_actived = 1;
        $app->ecosystem_auth = 0;
        $app->payments_active = 0;
        $app->is_public = 1;
        $app->domain_based = 0;
        $app->save();

        return $app;
    }
}
