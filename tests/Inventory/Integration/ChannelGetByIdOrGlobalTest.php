<?php

declare(strict_types=1);

namespace Tests\Inventory\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Channels\Repositories\ChannelRepository;
use Kanvas\Inventory\Channels\Services\ChannelService;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Inventory\Variants\Models\VariantsChannels;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Tests\TestCase;

final class ChannelGetByIdOrGlobalTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'inventory'];

    private Apps $currentApp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->currentApp = app(Apps::class);
    }

    private function createCompany(): Companies
    {
        return Companies::factory()->create(['users_id' => auth()->user()->getId()]);
    }

    /**
     * ChannelObserver::creating() resolves `$channel->company` to validate the "one default per
     * company" rule — a company-less (companies_id = 0) row has no such relation to check, so the
     * global channel is seeded via a raw insert, bypassing the observer entirely (this is also how
     * it would actually be provisioned in production: a one-time seed, not through CreateChannel).
     */
    private function createGlobalChannel(): Channels
    {
        $name = 'Global Channel ' . fake()->unique()->word();

        $id = DB::connection('inventory')->table('channels')->insertGetId([
            'users_id' => auth()->user()->getId(),
            'companies_id' => 0,
            'apps_id' => $this->currentApp->getId(),
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'slug' => Str::slug($name),
            'is_published' => 1,
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Channels::findOrFail($id);
    }

    public function testResolvesGlobalChannel(): void
    {
        $company = $this->createCompany();
        $global = $this->createGlobalChannel();

        $resolved = ChannelRepository::getByIdOrGlobal($global->getId(), $company, $this->currentApp);

        $this->assertSame($global->getId(), $resolved->getId());
        $this->assertSame(0, (int) $resolved->companies_id);
    }

    public function testResolvesCompanyOwnedChannel(): void
    {
        $company = $this->createCompany();
        $companyChannel = Channels::create([
            'users_id' => auth()->user()->getId(),
            'companies_id' => $company->getId(),
            'apps_id' => $this->currentApp->getId(),
            'name' => 'Company Channel ' . fake()->unique()->word(),
            'is_default' => 1,
            'is_published' => 1,
            'is_deleted' => 0,
        ]);

        $resolved = ChannelRepository::getByIdOrGlobal($companyChannel->getId(), $company, $this->currentApp);

        $this->assertSame($companyChannel->getId(), $resolved->getId());
        $this->assertSame($company->getId(), (int) $resolved->companies_id);
    }

    public function testThrowsWhenChannelBelongsToAnotherCompany(): void
    {
        $company = $this->createCompany();
        $otherCompany = $this->createCompany();
        $otherChannel = Channels::create([
            'users_id' => auth()->user()->getId(),
            'companies_id' => $otherCompany->getId(),
            'apps_id' => $this->currentApp->getId(),
            'name' => 'Other Channel ' . fake()->unique()->word(),
            'is_default' => 1,
            'is_published' => 1,
            'is_deleted' => 0,
        ]);

        $this->expectException(ModelNotFoundException::class);
        ChannelRepository::getByIdOrGlobal($otherChannel->getId(), $company, $this->currentApp);
    }

    /**
     * Regression for the real production incident: assigning a variant to a global channel via
     * updateVariant's `channels` input threw "No result found for model Channels", because
     * ChannelService::updateChannelVariant() resolved the channel with the strict getById() —
     * fixed by switching that call site (and the other Variants-mutation ones) to getByIdOrGlobal().
     */
    public function testUpdateChannelVariantResolvesAGlobalChannel(): void
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        new InventorySetup($this->currentApp, $user, $company)->run();

        $global = $this->createGlobalChannel();

        /** @var Products $product */
        $product = Products::factory()
            ->withAppId($this->currentApp->getId())
            ->withCompanyId($company->getId())
            ->create(['is_published' => 1, 'is_deleted' => 0]);
        $variant = $product->variants()->firstOrFail();
        $warehouse = Warehouses::fromApp($this->currentApp)->fromCompany($company)->firstOrFail();

        VariantsWarehouses::create([
            'products_variants_id' => $variant->getId(),
            'warehouses_id' => $warehouse->getId(),
            'quantity' => 10,
            'price' => 0,
            'position' => 0,
        ]);

        ChannelService::updateChannelVariant($variant, [
            [
                'warehouses_id' => $warehouse->getId(),
                'channels_id' => $global->getId(),
                'price' => 0.0,
                'is_published' => true,
            ],
        ]);

        $this->assertTrue(
            VariantsChannels::where('products_variants_id', $variant->getId())
                ->where('channels_id', $global->getId())
                ->exists()
        );
    }
}
