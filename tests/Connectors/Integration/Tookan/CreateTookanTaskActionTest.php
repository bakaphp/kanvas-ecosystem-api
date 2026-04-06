<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Tookan;

use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesAddress;
use Kanvas\Connectors\Tookan\Actions\CreateTookanTaskAction;
use Kanvas\Connectors\Tookan\Enums\ConfigurationEnum;
use Kanvas\Connectors\Tookan\Handlers\TookanHandler;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\TestCase;

final class CreateTookanTaskActionTest extends TestCase
{
    use HasIntegrationCompany;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('GITHUB_ACTIONS')) {
            $this->markTestSkipped('Tookan integration tests are skipped in CI');
        }
    }

    private function setupTookan(Apps $app, $company, $user): void
    {
        $app->set(ConfigurationEnum::API_KEY->value, env('TEST_TOOKAN_API_KEY'));
        $app->set(ConfigurationEnum::BASE_URL->value, env('TEST_TOOKAN_BASE_URL', ConfigurationEnum::SANDBOX_URL->value));

        $this->setIntegration(
            $app,
            IntegrationsEnum::TOOKAN,
            TookanHandler::class,
            $company,
            $user
        );
    }

    private function makeOrder(Apps $app, $company, $user, array $metadataData = []): Order
    {
        $region = Regions::getDefault($company, $app);

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create();

        return Order::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create([
                'region_id' => $region->getId(),
                'people_id' => $people->getId(),
                'metadata' => ['data' => $metadataData],
            ]);
    }

    public function testCreateSingleUsesMetadataLatLong(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();

        $this->setupTookan($app, $company, $user);

        $order = $this->makeOrder($app, $company, $user, [
            'latitude' => 40.7128,
            'longitude' => -74.006,
        ]);

        $result = new CreateTookanTaskAction(
            order: $order,
            company: $company,
            receiverUser: $user,
        )->createSingle();

        $this->assertArrayHasKey('job_id', $result);
        $this->assertArrayHasKey('tracking_link', $result);
        $this->assertNotNull($result['job_id']);
    }

    public function testCreateSingleWithReceiverCompany(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();

        $this->setupTookan($app, $company, $user);

        $order = $this->makeOrder($app, $company, $user);

        $result = new CreateTookanTaskAction(
            order: $order,
            company: $company,
            receiverCompany: $company,
        )->createSingle();

        $this->assertArrayHasKey('job_id', $result);
        $this->assertArrayHasKey('tracking_link', $result);
        $this->assertNotNull($result['job_id']);
    }

    public function testCreateMulti(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();

        $this->setupTookan($app, $company, $user);

        CompaniesAddress::firstOrCreate(
            ['companies_id' => $company->getId(), 'is_default' => true],
            [
                'address' => '123 Main Street',
                'address_2' => '',
                'city' => 'New York',
                'state' => 'NY',
                'zip' => '10001',
                'latitude' => 40.7128,
                'longitude' => -74.006,
            ]
        );

        $order = $this->makeOrder($app, $company, $user, [
            'latitude' => 40.7128,
            'longitude' => -74.006,
            'address' => '456 Customer Ave, New York, NY 10002',
        ]);

        $result = new CreateTookanTaskAction(
            order: $order,
            company: $company,
            receiverUser: $user,
        )->createMulti();

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }
}
