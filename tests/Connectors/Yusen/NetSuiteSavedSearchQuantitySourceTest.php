<?php

declare(strict_types=1);

namespace Tests\Connectors\Yusen;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Yusen\Enums\ConfigurationEnum;
use Kanvas\Connectors\Yusen\Services\YusenSettings;
use Kanvas\Connectors\Yusen\Sources\NetSuiteSavedSearchQuantitySource;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Users\Models\Users;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Row semantics for the NetSuite leg, driven directly rather than through the comparator — the
 * subtle part is what an absent `quantityAvailable` means, and that is decided here.
 */
class NetSuiteSavedSearchQuantitySourceTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem'];

    private Apps $kanvasApp;
    private Companies $kanvasCompany;
    private mixed $locationBeforeTest = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $this->kanvasCompany = Companies::getById($user->getCurrentCompany()->getId());
        $this->locationBeforeTest = $this->kanvasCompany->get(ConfigurationEnum::NETSUITE_LOCATION_ID->value);
    }

    /**
     * `set()` writes Redis before the DB, so the transaction rollback does not undo it — a test that
     * blanks a tenant config leaves it blanked for whoever shares this Redis.
     */
    protected function tearDown(): void
    {
        $this->kanvasCompany->set(
            ConfigurationEnum::NETSUITE_LOCATION_ID->value,
            $this->locationBeforeTest ?? ''
        );

        parent::tearDown();
    }

    public function testRefusesToRunWithoutALocation(): void
    {
        $this->kanvasCompany->set(ConfigurationEnum::NETSUITE_LOCATION_ID->value, '');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('yusen_netsuite_location_id is not set');

        $this->source()->quantities();
    }

    public function testAnAbsentQuantityIsZeroAvailableNotMissingData(): void
    {
        // Aero holds 741 with all 741 committed; NetSuite omits the element rather than sending 0.
        $quantities = $this->index([
            ['itemId' => '4511338003374', 'quantityAvailable' => 1221],
            ['itemId' => '4511338000045'],
        ]);

        $this->assertSame(1221.0, $quantities['4511338003374']);
        $this->assertSame(0.0, $quantities['4511338000045']);
    }

    public function testEveryRowAbsentMeansTheSearchIsBroken(): void
    {
        // A location where nothing is available anywhere is not a real inventory position — it is
        // a search with no location join, which previously reported the whole catalog as missing.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('no locationQuantityAvailable on any of them');

        $this->index([
            ['itemId' => '4511338003374'],
            ['itemId' => '4511338000045'],
        ]);
    }

    public function testCapturesDisplayNamesForTheMissingInYusenRows(): void
    {
        $source = $this->source();

        $this->index([
            ['itemId' => '4511338003374', 'displayName' => 'Copic Sketch Marker Dark Pink', 'quantityAvailable' => 1221],
        ], $source);

        $this->assertSame('Copic Sketch Marker Dark Pink', $source->describe('4511338003374'));
        $this->assertNull($source->describe('9999999999999'));
    }

    public function testSkipsRowsThatCarryNoItemId(): void
    {
        $quantities = $this->index([
            'not-an-array',
            ['displayName' => 'orphan row', 'quantityAvailable' => 5],
            ['itemId' => '4511338003374', 'quantityAvailable' => 1221],
        ]);

        $this->assertSame(['4511338003374' => 1221.0], $quantities);
    }

    /**
     * @param array<array-key, mixed> $products
     * @return array<string, float>
     */
    private function index(array $products, ?NetSuiteSavedSearchQuantitySource $source = null): array
    {
        $source ??= $this->source();

        $method = new ReflectionMethod($source, 'indexQuantities');
        $method->setAccessible(true);

        return $method->invoke($source, $products, '7');
    }

    private function source(): NetSuiteSavedSearchQuantitySource
    {
        return new NetSuiteSavedSearchQuantitySource(
            $this->kanvasApp,
            $this->kanvasCompany,
            new YusenSettings($this->kanvasApp, $this->kanvasCompany),
        );
    }
}
