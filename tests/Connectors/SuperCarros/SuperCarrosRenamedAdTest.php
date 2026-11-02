<?php

declare(strict_types=1);

namespace Tests\Connectors\SuperCarros;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\SuperCarros\Actions\SuperCarrosVehicleInventoryImportAction;
use Kanvas\Connectors\SuperCarros\DataTransferObjects\Vehicle;
use Kanvas\Connectors\SuperCarros\DataTransferObjects\VehicleColors;
use Kanvas\Connectors\SuperCarros\DataTransferObjects\VehicleContact;
use Kanvas\Connectors\SuperCarros\DataTransferObjects\VehicleEngine;
use Kanvas\Connectors\SuperCarros\DataTransferObjects\VehicleFinancing;
use Kanvas\Connectors\SuperCarros\DataTransferObjects\VehicleImages;
use Kanvas\Connectors\SuperCarros\DataTransferObjects\VehicleLinks;
use Kanvas\Connectors\SuperCarros\DataTransferObjects\VehiclePrice;
use Kanvas\Connectors\SuperCarros\DataTransferObjects\VehicleSpecifications;
use Kanvas\Connectors\SuperCarros\DataTransferObjects\VehicleVideo;
use Kanvas\Connectors\SuperCarros\Services\VehicleService;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Tests\Inventory\Integration\Imports\PublishesChannelVariants;
use Tests\TestCase;

/**
 * Real case: ad 1409738 went "Toyota Yaris Cross Full Híbrido" → "Toyota Yaris Cross Full Hibrid",
 * the name-based slug changed, and the import forked a second, variant-less product that kept coming
 * back into the search index.
 */
final class SuperCarrosRenamedAdTest extends TestCase
{
    use PublishesChannelVariants;

    public function testARenamedAdUpdatesTheProductThatOwnsItsVariant(): void
    {
        [$channel] = $this->publishVariantsInANewChannel(0);
        $adId = random_int(10_000_000, 99_999_999);

        $this->import($channel, $adId, 'Toyota Yaris Cross Full Híbrido');
        $this->import($channel, $adId, 'Toyota Yaris Cross Full Hibrid');

        $products = $this->productsForAd($adId);
        $this->assertCount(1, $products, 'A rename must not fork a second product for the same ad.');

        $product = $products->first();
        $this->assertSame('Toyota Yaris Cross Full Hibrid', $product->name);

        $variant = Variants::where('sku', SuperCarrosVehicleInventoryImportAction::variantSku($adId))
            ->where('products_id', $product->getId())
            ->first();
        $this->assertNotNull($variant, 'The ad variant must stay on the product being updated.');
    }

    public function testANewAdStillGetsANameBasedSlug(): void
    {
        [$channel] = $this->publishVariantsInANewChannel(0);
        $adId = random_int(10_000_000, 99_999_999);

        $this->import($channel, $adId, 'Toyota Yaris Cross Full Híbrido');

        $this->assertSame(
            'toyota-yaris-cross-full-hibrido-' . $adId,
            $this->productsForAd($adId)->first()?->slug
        );
    }

    private function productsForAd(int $adId)
    {
        $company = auth()->user()->getCurrentCompany();

        return Products::fromApp(app(Apps::class))
            ->fromCompany($company)
            ->where('slug', 'like', '%-' . $adId)
            ->get();
    }

    private function import(Channels $channel, int $adId, string $fullName): void
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        $result = new SuperCarrosVehicleInventoryImportAction(
            app: $app,
            company: $company,
            user: $user,
            region: Regions::fromApp($app)->fromCompany($company)->firstOrFail(),
            warehouse: Warehouses::fromApp($app)->fromCompany($company)->firstOrFail(),
            channel: $channel,
            vehicleService: $this->fakeVehicleService($this->vehicle($adId, $fullName)),
        )->execute();

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['imported']);
    }

    private function vehicle(int $adId, string $fullName): Vehicle
    {
        return new Vehicle(
            id: $adId,
            customerId: 1,
            customerName: 'Dealer',
            brand: 'Toyota',
            model: 'Yaris Cross',
            fullName: $fullName,
            year: '2027',
            price: new VehiclePrice(amount: 25000, currency: 'USD'),
            condition: 'Usado',
            fuelType: 'Híbrido',
            type: 'Jeepeta',
            transmission: 'Automática',
            engine: new VehicleEngine(type: '', cylinders: '', description: ''),
            colors: new VehicleColors(exterior: '', interior: ''),
            specifications: new VehicleSpecifications(
                doors: 4,
                passengers: 5,
                usage: '',
                usageUnit: '',
                traction: ''
            ),
            images: new VehicleImages(main: '', mainBig: '', gallery: []),
            links: new VehicleLinks(ad: '', financing: ''),
            description: '',
            components: [],
            financing: new VehicleFinancing(
                available: false,
                termMonths: '',
                downPayment: '',
                amountToFinance: '',
                monthlyPayment: ''
            ),
            contact: new VehicleContact(
                hasContact: false,
                fullName: '',
                title: '',
                phone: '',
                phone2: '',
                cellPhone: '',
                whatsapp: '',
                whatsappLink: ''
            ),
            video: new VehicleVideo(
                hasVideo: false,
                source: '',
                videoId: '',
                muted: false,
                embedUrl: ''
            ),
        );
    }

    private function fakeVehicleService(Vehicle $vehicle): VehicleService
    {
        return new class ($vehicle) extends VehicleService {
            public function __construct(
                private readonly Vehicle $vehicle,
            ) {
            }

            public function getCustomerVehicles(): array
            {
                return ['vehicles' => [['Id' => $this->vehicle->id]]];
            }

            public function getVehicleDetails(int $adId): Vehicle
            {
                return $this->vehicle;
            }
        };
    }
}
