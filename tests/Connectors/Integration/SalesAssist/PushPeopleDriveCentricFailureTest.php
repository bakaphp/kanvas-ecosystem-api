<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\SalesAssist;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\DriveCentric\Enums\ConfigurationEnum;
use Kanvas\Connectors\DriveCentric\Enums\CustomFieldEnums;
use Kanvas\Connectors\DriveCentric\Exceptions\DriveCentricException;
use Kanvas\Connectors\SalesAssist\Activities\PushPeopleActivity;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Regions\Models\Regions;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Models\Integrations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * DriveCentric record rejections (400/404/409/422, or not synced yet) are data problems: they come back as
 * failWorkflow (no 'trace' key) and are logged. Auth, rate-limit and server errors must still reach
 * executeIntegration's catch, which report()s them to Sentry.
 */
#[Group('serial')]
final class PushPeopleDriveCentricFailureTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['crm', 'ecosystem', 'workflow'];

    private const string BASE_URL = 'https://drivecentric.test';

    /**
     * Settings are written to Redis before the DB, and the rollback never reaches Redis — without this the
     * fake base URL and store id outlive the test and turn the shared company into a DriveCentric one.
     */
    protected function tearDown(): void
    {
        app(Apps::class)->del(ConfigurationEnum::BASE_URL->value);
        $company = auth()->user()->getCurrentCompany();
        $company->del(ConfigurationEnum::STORE_ID->value);
        $company->del('use_global_workflows');

        parent::tearDown();
    }

    public function testCustomerMissingInCrmIsLoggedAndFailsWorkflow(): void
    {
        Log::spy();
        Exceptions::fake();

        $people = $this->createPeople(customerId: null);

        $result = $this->runActivity($people);

        $this->assertStringContainsString('Customer does not exist in DriveCentric', $result['error']);
        $this->assertEquals($people->getId(), $result['people_id']);
        $this->assertArrayNotHasKey('trace', $result);
        $this->assertWarningLogged($people);
        Exceptions::assertNothingReported();
    }

    public function testClientErrorIsLoggedAndFailsWorkflow(): void
    {
        Log::spy();
        Exceptions::fake();
        $this->fakeCustomerUpdateResponse(400, [
            'code' => 400,
            'message' => "Validation failed for these properties: 'Customer.FirstName', 'Customer.LastName'",
        ]);

        $people = $this->createPeople(customerId: 'dc-customer-1');

        $result = $this->runActivity($people);

        $this->assertStringContainsString('HTTP 400', $result['error']);
        $this->assertEquals('DriveCentric', $result['crm']);
        $this->assertArrayNotHasKey('trace', $result);
        $this->assertWarningLogged($people);
        Exceptions::assertNothingReported();
    }

    public static function reportableStatusProvider(): array
    {
        return [
            'expired token' => [401],
            'forbidden' => [403],
            'rate limited' => [429],
            'server error' => [500],
            'unavailable' => [503],
        ];
    }

    #[DataProvider('reportableStatusProvider')]
    public function testNonDataErrorsStillReportToSentry(int $status): void
    {
        Log::spy();
        Exceptions::fake();
        $this->fakeCustomerUpdateResponse($status, ['code' => $status, 'message' => 'Upstream failure']);

        $people = $this->createPeople(customerId: 'dc-customer-1');

        $result = $this->runActivity($people);

        $this->assertStringContainsString("HTTP {$status}", $result['error']);
        $this->assertArrayHasKey('trace', $result);
        Exceptions::assertReported(fn (DriveCentricException $e) => $e->getCode() === $status);
        Log::shouldNotHaveReceived('warning');
    }

    public function testFailedAuthenticationStillReportsToSentry(): void
    {
        Log::spy();
        Exceptions::fake();
        Http::fake([
            self::BASE_URL . '/api/authentication/token' => Http::response(['message' => 'Invalid client'], 401),
        ]);

        $people = $this->createPeople(customerId: 'dc-customer-1');

        $result = $this->runActivity($people);

        $this->assertStringContainsString('Failed to authenticate with DriveCentric', $result['error']);
        $this->assertArrayHasKey('trace', $result);
        Exceptions::assertReported(fn (DriveCentricException $e) => $e->getCode() === 401);
        Log::shouldNotHaveReceived('warning');
    }

    private function createPeople(?string $customerId): People
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $app->set(ConfigurationEnum::BASE_URL->value, self::BASE_URL);
        $company->set('use_global_workflows', true);
        $company->set(ConfigurationEnum::STORE_ID->value, 'test-store');
        Cache::forget('drive_centric_token_' . $app->getId() . '_' . $company->getId());

        $people = People::factory()
            ->withAppId($app->getId())
            ->withUserId($user->getId())
            ->withCompanyId($company->getId())
            ->create();

        if ($customerId !== null) {
            $people->set(CustomFieldEnums::DRIVE_CENTRIC_CUSTOMER_ID->value, $customerId);
        }

        $region = Regions::getDefault($company, $app) ?? Regions::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => 0,
            'name' => 'Region ' . uniqid(),
            'is_default' => 1,
            'is_deleted' => 0,
        ]);

        IntegrationsCompany::firstOrCreate(
            [
                'companies_id' => $company->getId(),
                'integrations_id' => Integrations::getByName(IntegrationsEnum::INTERNAL->value)->getId(),
                'region_id' => $region->getId(),
            ],
            [
                'status_id' => Status::where('slug', StatusEnum::ACTIVE->value)->where('apps_id', 0)->firstOrFail()->getId(),
                'is_active' => 1,
            ]
        );

        return $people;
    }

    private function fakeCustomerUpdateResponse(int $status, array $body): void
    {
        Http::fake([
            self::BASE_URL . '/api/authentication/token' => Http::response(['idToken' => 'test-token']),
            self::BASE_URL . '/api/stores/*/customers/*' => Http::response($body, $status),
        ]);
    }

    private function runActivity(People $people): array
    {
        $activity = new class () extends PushPeopleActivity {
            public function __construct()
            {
            }

            public function workflowId()
            {
                return null;
            }
        };

        return $activity->execute($people, app(Apps::class), []);
    }

    private function assertWarningLogged(People $people): void
    {
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context) => str_contains($message, 'DriveCentric')
                && $context['people_id'] === $people->getId())
            ->once();
    }
}
