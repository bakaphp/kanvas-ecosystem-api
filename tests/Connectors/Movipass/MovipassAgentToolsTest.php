<?php

declare(strict_types=1);

namespace Tests\Connectors\Movipass;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Movipass\Enums\CustomFieldEnum;
use Kanvas\Connectors\Movipass\Enums\MechanicAvailabilityEnum;
use Kanvas\Connectors\Movipass\Enums\MovipassRolesEnum;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Connectors\Movipass\Neuron\Tools\ListMechanicsTool;
use Kanvas\Connectors\Movipass\Neuron\Tools\MechanicOrdersTool;
use Kanvas\Connectors\Movipass\Neuron\Tools\RoadsideAssistanceMetricsTool;
use Kanvas\Connectors\Movipass\Repositories\MechanicsRepository;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Users\Models\Users;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

/**
 * The authenticated user doubles as the mechanic: mechanics are just app users carrying a field
 * role plus the availability/service-type user_config entries, and the auth user is the only one
 * already associated to both the app and the company the way UserAppRepository requires.
 */
final class MovipassAgentToolsTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce', 'crm'];

    private Apps $currentApp;
    private Companies $company;
    private Users $mechanic;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->mechanic = auth()->user();
        $this->company = $this->mechanic->getCurrentCompany();

        Bouncer::scope()->to(RolesEnums::getScope($this->currentApp, global: true));
        Bouncer::assign(MovipassRolesEnum::TRUCK_DRIVER->value)->to($this->mechanic);
    }

    private function setAvailability(string $availability): void
    {
        $this->mechanic->set(CustomFieldEnum::MECHANIC_AVAILABILITY->value, $availability);
        $this->mechanic->set(CustomFieldEnum::MECHANIC_SERVICE_TYPE->value, 'ASISTENCIA VIAL');
    }

    private function makeRoadsideOrder(int $mechanicId): Order
    {
        $type = OrderTypes::firstOrCreate([
            'apps_id' => $this->currentApp->getId(),
            'companies_id' => $this->company->getId(),
            'name' => OrderTypeEnum::ROADSIDE_ASSISTANCE->value,
        ]);

        return Order::factory()
            ->withAppId($this->currentApp->getId())
            ->withCompanyId($this->company->getId())
            ->withUserId($this->mechanic->getId())
            ->create([
                'order_types_id' => $type->getId(),
                'status' => 'completed',
                'total_gross_amount' => 75.0,
                'total_net_amount' => 75.0,
                'metadata' => [
                    'assistance_case' => [
                        'mechanic' => ['user_id' => $mechanicId, 'company_id' => $this->company->getId()],
                        'notified_mechanic_ids' => [$mechanicId],
                    ],
                ],
            ]);
    }

    public function testListMechanicsReturnsRosterWithAvailabilityAndServiceType(): void
    {
        $this->setAvailability(MechanicAvailabilityEnum::ACTIVO->value);

        $result = new ListMechanicsTool()
            ->withContext($this->currentApp, $this->company, $this->mechanic)
            ->__invoke(limit: 100);

        $row = collect($result['mechanics'])->firstWhere('id', $this->mechanic->getId());
        $this->assertNotNull($row);
        $this->assertSame(MechanicAvailabilityEnum::ACTIVO->value, $row['availability']);
        $this->assertSame('ASISTENCIA VIAL', $row['service_type']);
        $this->assertContains(MovipassRolesEnum::TRUCK_DRIVER->value, $row['roles']);
    }

    public function testListMechanicsSearchNarrowsTheRoster(): void
    {
        $result = new ListMechanicsTool()
            ->withContext($this->currentApp, $this->company, $this->mechanic)
            ->__invoke(search: $this->mechanic->email, limit: 100);

        $this->assertSame(1, (int) $result['count']);
        $this->assertSame($this->mechanic->getId(), $result['mechanics'][0]['id']);
    }

    /**
     * Availability and service type live in user_config, which HashTableTrait writes on the
     * `ecosystem` connection while this query reads it through Users on `mysql` — inside a test
     * transaction that row is invisible, so the filter is asserted on the query it builds.
     */
    public function testMechanicsQueryFiltersOnTheAvailabilityCustomField(): void
    {
        $filtered = MechanicsRepository::query(
            $this->currentApp,
            $this->company->getId(),
            MechanicAvailabilityEnum::ACTIVO->value,
        );

        $this->assertStringContainsString('user_config', $filtered->toSql());
        $this->assertContains(CustomFieldEnum::MECHANIC_AVAILABILITY->value, $filtered->getBindings());
        $this->assertContains(MechanicAvailabilityEnum::ACTIVO->value, $filtered->getBindings());

        $unfiltered = MechanicsRepository::query($this->currentApp, $this->company->getId());

        $this->assertNotContains(
            CustomFieldEnum::MECHANIC_AVAILABILITY->value,
            $unfiltered->getBindings()
        );
    }

    public function testMechanicOrdersReturnsAssignedCases(): void
    {
        $order = $this->makeRoadsideOrder($this->mechanic->getId());

        $result = new MechanicOrdersTool()
            ->withContext($this->currentApp, $this->company, $this->mechanic)
            ->__invoke(mechanic_id: $this->mechanic->getId());

        $this->assertSame(1, (int) $result['total_cases']);
        $this->assertSame($order->order_number, $result['cases'][0]['order_number']);
        $this->assertSame('completed', $result['cases'][0]['status']);
    }

    public function testMechanicOrdersNotifiedFilterMatchesOfferedCases(): void
    {
        $this->makeRoadsideOrder($this->mechanic->getId());

        $result = new MechanicOrdersTool()
            ->withContext($this->currentApp, $this->company, $this->mechanic)
            ->__invoke(mechanic_id: $this->mechanic->getId(), mechanic_filter: 'NOTIFIED');

        $this->assertSame(1, (int) $result['total_cases']);
    }

    public function testMechanicOrdersIgnoresAnotherMechanicsCases(): void
    {
        $this->makeRoadsideOrder($this->mechanic->getId());

        $result = new MechanicOrdersTool()
            ->withContext($this->currentApp, $this->company, $this->mechanic)
            ->__invoke(mechanic_id: $this->mechanic->getId() + 999999);

        $this->assertSame(0, (int) $result['total_cases']);
    }

    public function testRoadsideMetricsCountsCasesForTheCompany(): void
    {
        $this->makeRoadsideOrder($this->mechanic->getId());

        $result = new RoadsideAssistanceMetricsTool()
            ->withContext($this->currentApp, $this->company, $this->mechanic)
            ->__invoke(since: now()->subDay()->toDateString(), until: now()->toDateString());

        $this->assertArrayHasKey('period', $result);
        $this->assertSame(1, (int) $result['totalCases']);
        $this->assertArrayHasKey('avgResponseHours', $result);
    }

    public function testRoadsideMetricsIgnoresAnotherProvidersCases(): void
    {
        $this->makeRoadsideOrder($this->mechanic->getId());

        $result = new RoadsideAssistanceMetricsTool()
            ->withContext($this->currentApp, $this->company, $this->mechanic)
            ->__invoke(
                since: now()->subDay()->toDateString(),
                until: now()->toDateString(),
                provider_company_id: $this->company->getId() + 999999,
            );

        $this->assertSame(0, (int) $result['totalCases']);
    }
}
