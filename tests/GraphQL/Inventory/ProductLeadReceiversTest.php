<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Zoho\Jobs\SyncZohoLeadFromReceiverJob;
use Kanvas\Guild\Leads\Jobs\CreateLeadsFromReceiverJob;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
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

    /**
     * Eager loading builds one constraint for the whole batch. A single-column hasMany keyed on
     * companies_id plus `where apps_id = first row's app` would hand app A's receivers to app B's
     * product; the composite key must keep each row on its own tenant.
     */
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

    public function testSubmitWebhookUuidMirrorsTheReceiverUuidByDefault(): void
    {
        // LeadReceiverObserver wires a webhook under the receiver's own uuid on creation.
        $product = $this->createProductForCurrentCompany();
        $receiver = $this->createReceiver(app(Apps::class), $product->company, 'tradeIn');

        $this->assertSame($receiver->uuid, $this->autoWebhook($receiver)->uuid);
        $this->assertSame($receiver->uuid, $this->submitUuidFor($product)[$receiver->getId()]);
    }

    public function testSubmitWebhookUuidResolvesThroughGraphQL(): void
    {
        $app = app(Apps::class);
        $product = $this->createProductForCurrentCompany();
        $company = $product->company;

        $unwired = $this->createReceiver($app, $company, 'contact');
        $this->autoWebhook($unwired)->update(['is_deleted' => 1]);

        $contested = $this->createReceiver($app, $company, 'offers');
        $this->createWebhook($app, $company, $contested->getId(), isActive: false);
        $newerActive = $this->createWebhook($app, $company, $contested->getId());

        $asString = $this->createReceiver($app, $company, 'finance');
        $this->autoWebhook($asString)->update(['is_deleted' => 1]);
        $stringWebhook = $this->createWebhook($app, $company, (string) $asString->getId());

        $zohoOnly = $this->createReceiver($app, $company, 'service');
        $this->autoWebhook($zohoOnly)->update(['is_deleted' => 1]);
        $this->createWebhook($app, $company, $zohoOnly->getId(), action: SyncZohoLeadFromReceiverJob::class);

        $response = $this->queryProduct($product, '
            leadReceivers { id name submit_webhook_uuid }
            variants { leadReceivers { id submit_webhook_uuid } }
        ')
            ->assertSuccessful()
            ->json('data.products.data.0');

        $byId = collect($response['leadReceivers'])->keyBy('id');

        $this->assertNull($byId[$unwired->getId()]['submit_webhook_uuid']);
        $this->assertSame($newerActive->uuid, $byId[$contested->getId()]['submit_webhook_uuid']);
        $this->assertSame($stringWebhook->uuid, $byId[$asString->getId()]['submit_webhook_uuid']);
        $this->assertNull($byId[$zohoOnly->getId()]['submit_webhook_uuid']);

        $variantById = collect($response['variants'][0]['leadReceivers'])->keyBy('id');
        $this->assertSame($newerActive->uuid, $variantById[$contested->getId()]['submit_webhook_uuid']);
    }

    public function testInactiveWebhookIsUsedOnlyWhenNoActiveOneExists(): void
    {
        $product = $this->createProductForCurrentCompany();
        $receiver = $this->createReceiver(app(Apps::class), $product->company, 'tradeIn');
        $inactive = $this->autoWebhook($receiver);
        $inactive->update(['is_active' => false]);

        $this->assertSame($inactive->uuid, $this->submitUuidFor($product)[$receiver->getId()]);
    }

    /** @return array<int, string|null> receiver id => submit_webhook_uuid */
    private function submitUuidFor(Products $product): array
    {
        return collect($this->queryProduct($product, 'leadReceivers { id submit_webhook_uuid }')
            ->json('data.products.data.0.leadReceivers'))
            ->pluck('submit_webhook_uuid', 'id')
            ->all();
    }

    private function autoWebhook(LeadReceiver $receiver): ReceiverWebhook
    {
        return ReceiverWebhook::query()->where('uuid', $receiver->uuid)->firstOrFail();
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

    private function createWebhook(
        Apps $app,
        Companies $company,
        int|string $receiverId,
        bool $isActive = true,
        string $action = CreateLeadsFromReceiverJob::class
    ): ReceiverWebhook {
        $workflowAction = WorkflowAction::firstOrCreate(
            ['model_name' => $action],
            ['name' => class_basename($action)],
        );

        return ReceiverWebhook::factory()
            ->app($app->getId())
            ->user($company->users_id)
            ->company($company->getId())
            ->create([
                'action_id' => $workflowAction->getId(),
                'is_active' => $isActive,
                'configuration' => ['receiver_id' => $receiverId],
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
