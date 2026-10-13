<?php

declare(strict_types=1);

namespace Tests\Connectors\SuperCarros;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\SuperCarros\Actions\SuperCarrosVehicleInventoryImportAction;
use Kanvas\Connectors\SuperCarros\DataTransferObjects\Vehicle;
use Kanvas\Connectors\SuperCarros\Services\VehicleService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use RuntimeException;
use Tests\Inventory\Integration\Imports\PublishesChannelVariants;
use Tests\TestCase;

/**
 * Vehicle details always fail here, so nothing is actually imported: what is under test is that
 * a vehicle on the list still counts as present, and that only --unpublish-all sweeps.
 */
final class SuperCarrosUnpublishMissingTest extends TestCase
{
    use PublishesChannelVariants;

    public function testUnpublishMissingRemovesOnlyVehiclesTheListDidNotSend(): void
    {
        [$channel, [$first, $second, $sold]] = $this->vehiclesInANewChannel(3);

        $result = $this->import($channel, [$first, $second], unpublishMissing: true);

        $this->assertSame(1, $result['unpublished']);
        $this->assertSame(1, $this->publishedIn($channel, $first));
        $this->assertSame(1, $this->publishedIn($channel, $second));
        $this->assertSame(0, $this->publishedIn($channel, $sold));
    }

    public function testASiblingFeedIntoTheSameCompanyKeepsItsVehicles(): void
    {
        [$channel, [$first, $second, $siblingFeed]] = $this->vehiclesInANewChannel(3);

        // The --customer_id feed without --unpublish-all, then the one that sweeps.
        $this->import($channel, [$siblingFeed], unpublishMissing: false);
        $result = $this->import($channel, [$first, $second], unpublishMissing: true);

        $this->assertSame(0, $result['unpublished']);
        $this->assertSame(1, $this->publishedIn($channel, $siblingFeed));
    }

    public function testWithoutTheFlagNothingIsUnpublished(): void
    {
        [$channel, [$first, $second, $sold]] = $this->vehiclesInANewChannel(3);

        $this->import($channel, [$first, $second], unpublishMissing: false);

        $this->assertSame(1, $this->publishedIn($channel, $sold));
    }

    public function testAFailedVehicleListUnpublishesNothing(): void
    {
        [$channel, $variants] = $this->vehiclesInANewChannel(2);

        $result = $this->import($channel, null, unpublishMissing: true);

        $this->assertFalse($result['success']);
        foreach ($variants as $variant) {
            $this->assertSame(1, $this->publishedIn($channel, $variant));
        }
    }

    public function testWithoutAnInjectedServiceTheRealVehicleServiceIsBuilt(): void
    {
        [$channel] = $this->vehiclesInANewChannel(1);
        $user = auth()->user();
        $app = app(Apps::class);

        // The test company has no SuperCarros credentials, so reaching the real Client's config
        // check proves the action still builds the real service with valid arguments.
        $this->expectException(ValidationException::class);

        new SuperCarrosVehicleInventoryImportAction(
            app: $app,
            company: $user->getCurrentCompany(),
            user: $user,
            region: $this->region(),
            channel: $channel,
        )->execute();
    }

    /**
     * SuperCarros variant SKUs are the numeric ad id.
     *
     * @return array{0: Channels, 1: list<Variants>}
     */
    private function vehiclesInANewChannel(int $count): array
    {
        [$channel, $variants] = $this->publishVariantsInANewChannel($count);
        $this->resku($variants, fn () => (string) random_int(10_000_000, 99_999_999));

        return [$channel, $variants];
    }

    /**
     * @param list<Variants>|null $listed null makes the vehicle list call fail
     */
    private function import(Channels $channel, ?array $listed, bool $unpublishMissing): array
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        return new SuperCarrosVehicleInventoryImportAction(
            app: $app,
            company: $company,
            user: $user,
            region: $this->region(),
            warehouse: Warehouses::fromApp($app)->fromCompany($company)->firstOrFail(),
            channel: $channel,
            unpublishMissing: $unpublishMissing,
            vehicleService: $this->fakeVehicleService($listed),
        )->execute();
    }

    private function region(): Regions
    {
        return Regions::fromApp(app(Apps::class))->fromCompany(auth()->user()->getCurrentCompany())->firstOrFail();
    }

    /**
     * @param list<Variants>|null $listed
     */
    private function fakeVehicleService(?array $listed): VehicleService
    {
        return new class ($listed) extends VehicleService {
            public function __construct(
                private readonly ?array $listed,
            ) {
            }

            public function getCustomerVehicles(): array
            {
                if ($this->listed === null) {
                    throw new RuntimeException('SuperCarros is down');
                }

                return ['vehicles' => array_map(fn (Variants $variant) => ['Id' => (int) $variant->sku], $this->listed)];
            }

            public function getVehicleDetails(int $adId): Vehicle
            {
                throw new RuntimeException('Details unavailable for ' . $adId);
            }
        };
    }
}
