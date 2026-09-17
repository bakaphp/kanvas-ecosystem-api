<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass\ParkingApplication;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationFieldEnum as CorporateField;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Movipass\Handlers\MovipassHandler;
use Kanvas\Connectors\Movipass\ParkingApplications\Enums\ParkingApplicationFieldEnum as Field;
use Kanvas\Connectors\Movipass\ParkingApplications\Enums\ParkingApplicationStatusEnum;
use Kanvas\Connectors\Movipass\Workflows\Activities\PublishApprovedParkingActivity;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\TestCase;

final class PublishApprovedParkingActivityTest extends TestCase
{
    use DatabaseTransactions;
    use HasIntegrationCompany;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'crm', 'inventory', 'event'];

    protected Apps $kanvasApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('GITHUB_ACTIONS')) {
            $this->markTestSkipped('Movipass corporate workflow tests are skipped in CI');
        }

        $this->kanvasApp = app(Apps::class);
        $user = Auth::user();
        $this->company = $user->getCurrentCompany();

        $this->setIntegration(
            $this->kanvasApp,
            IntegrationsEnum::MOVIPASS,
            MovipassHandler::class,
            $this->company,
            $user
        );

        new InventorySetup($this->kanvasApp, $user, $this->company)->run();
    }

    public function testSkipsACorporateApplicationThatIsNotAParking(): void
    {
        $lead = $this->lead([]);

        $result = $this->runActivity($lead);

        $this->assertSame('skipped', $result['status']);
        $this->assertNull(Field::PRODUCT_ID->readFrom($lead->fresh()));
    }

    public function testPublishesAParkingApplication(): void
    {
        $lead = $this->lead($this->fixtureFields());

        $result = $this->runActivity($lead);

        $this->assertSame('published', $result['status']);
        $this->assertSame((string) $result['product_id'], (string) Field::PRODUCT_ID->readFrom($lead->fresh()));
        $this->assertSame(ParkingApplicationStatusEnum::PUBLISHED->value, Field::STATUS->readFrom($lead->fresh()));
    }

    public function testMalformedApplicationIsFlaggedFailedWithTheKeyNotThrown(): void
    {
        $lead = $this->lead([Field::STRUCTURE->value => 'Mixto'] + $this->fixtureFields());

        $result = $this->runActivity($lead);

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString(Field::STRUCTURE->value, $result['reason']);
        $this->assertStringContainsString(Field::STRUCTURE->value, (string) Field::STATUS_REASON->readFrom($lead->fresh()));
        $this->assertNull(Field::PRODUCT_ID->readFrom($lead->fresh()));
    }

    private function runActivity(Lead $lead): array
    {
        $activity = new PublishApprovedParkingActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );

        return $activity->execute($lead, $this->kanvasApp, []);
    }

    private function lead(array $fields): Lead
    {
        $lead = Lead::factory()
            ->withAppAndCompany($this->kanvasApp->getId(), $this->company->getId())
            ->create(['title' => 'Parqueo Plaza Central']);

        foreach ($fields as $key => $value) {
            $lead->set($key, $value);
        }

        CorporateField::COMPANY_ID->writeTo($lead, (string) $this->company->getId());

        return $lead->fresh();
    }

    private function fixtureFields(): array
    {
        return json_decode(
            (string) file_get_contents(__DIR__ . '/Fixtures/parking_application_custom_fields.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }
}
