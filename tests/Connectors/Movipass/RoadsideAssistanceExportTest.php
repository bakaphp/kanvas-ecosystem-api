<?php

declare(strict_types=1);

namespace Tests\Connectors\Movipass;

use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Enums\CustomFieldEnum;
use Kanvas\Connectors\Movipass\Enums\MovipassRolesEnum;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Users\Models\Users;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

final class RoadsideAssistanceExportTest extends TestCase
{
    protected Apps $apps;
    protected Users $authUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apps = app(Apps::class);
        $this->authUser = auth()->user();

        $this->roadsideOrderType();
    }

    public function testExportMechanicOrdersReturnsDownloadableExcel(): void
    {
        $this->createRoadsideOrder();

        $response = $this->graphQL('
            query ExportCases($format: ExportFormat!) {
                exportMechanicOrders(format: $format, all: true) {
                    status
                    download_url
                    file_name
                    message
                }
            }
        ', ['format' => 'EXCEL'], [], [
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $response->assertSuccessful();
        $this->assertSame('success', $response->json('data.exportMechanicOrders.status'));
        $this->assertNotNull($response->json('data.exportMechanicOrders.download_url'));
        $this->assertStringContainsString('xlsx', $response->json('data.exportMechanicOrders.file_name'));
        $this->assertStringContainsString('roadside_assistance_cases', $response->json('data.exportMechanicOrders.file_name'));
    }

    public function testExportMechanicOrdersWithCustomFieldMapper(): void
    {
        $this->createRoadsideOrder();

        $response = $this->graphQL('
            query ExportCases($format: ExportFormat!, $fieldMapper: Mixed!) {
                exportMechanicOrders(format: $format, all: true, field_mapper: $fieldMapper) {
                    status
                    file_name
                }
            }
        ', [
            'format' => 'EXCEL',
            'fieldMapper' => [
                'ID' => 'id',
                'Servicio' => 'metadata.assistance_case.service',
                'Mecánico' => 'metadata.assistance_case.mechanic.name',
            ],
        ], [], [
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $response->assertSuccessful();
        $this->assertSame('success', $response->json('data.exportMechanicOrders.status'));
    }

    public function testExportMechanicsReturnsDownloadableExcel(): void
    {
        Bouncer::scope()->to(RolesEnums::getScope($this->apps));
        Bouncer::assign(MovipassRolesEnum::TRUCK_DRIVER->value)->to($this->authUser);
        Bouncer::refresh();

        $this->authUser->set(CustomFieldEnum::MECHANIC_AVAILABILITY->value, 'activo');
        $this->authUser->set(CustomFieldEnum::MECHANIC_LAT->value, 18.486);
        $this->authUser->set(CustomFieldEnum::MECHANIC_LNG->value, -69.931);

        $response = $this->graphQL('
            query ExportMechanics($format: ExportFormat!) {
                exportMechanics(format: $format) {
                    status
                    download_url
                    file_name
                    message
                }
            }
        ', ['format' => 'EXCEL'], [], [
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $response->assertSuccessful();

        $status = $response->json('data.exportMechanics.status');
        $this->assertContains($status, ['success', 'warning']);

        if ($status === 'success') {
            $this->assertNotNull($response->json('data.exportMechanics.download_url'));
            $this->assertStringContainsString('xlsx', $response->json('data.exportMechanics.file_name'));
            $this->assertStringContainsString('roadside_assistance_mechanics', $response->json('data.exportMechanics.file_name'));
        }
    }

    private function roadsideOrderType(): OrderTypes
    {
        return OrderTypes::firstOrCreate([
            'name' => OrderTypeEnum::ROADSIDE_ASSISTANCE->value,
            'apps_id' => $this->apps->getId(),
        ]);
    }

    private function createRoadsideOrder(): Order
    {
        $case = [
            'case_id' => fake()->uuid(),
            'status' => 'service_completed',
            'requested_at' => '2026-06-01T10:00:00+00:00',
            'service' => 'Cambio de neumático',
            'mechanic' => [
                'name' => 'Juan Mecánico',
                'phone' => '8095551234',
                'company_name' => 'Grúas del Caribe',
            ],
            'dispatched_at' => '2026-06-01T10:10:00+00:00',
            'arrived_at' => '2026-06-01T10:40:00+00:00',
            'completed_at' => '2026-06-01T11:05:00+00:00',
            'user_rating' => 5,
        ];

        return Order::factory()
            ->withCompanyId($this->authUser->getCurrentCompany()->getId())
            ->withUserId($this->authUser->getId())
            ->create([
                'order_types_id' => $this->roadsideOrderType()->getId(),
                'user_email' => 'cliente@movipass.test',
                'user_phone' => '8090000000',
                'metadata' => ['assistance_case' => $case, 'data' => ['assistance_case' => $case]],
            ]);
    }
}
