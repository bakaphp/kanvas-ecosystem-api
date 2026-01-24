<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Tookan;

use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Tookan\DataTransferObject\CustomerDetail;
use Kanvas\Connectors\Tookan\DataTransferObject\DeliveryDetail;
use Kanvas\Connectors\Tookan\DataTransferObject\PickupDetail;
use Kanvas\Connectors\Tookan\DataTransferObject\TaskDetail;
use Kanvas\Connectors\Tookan\DataTransferObject\TaskMultipleDetail;
use Kanvas\Connectors\Tookan\Enums\ConfigurationEnum;
use Kanvas\Connectors\Tookan\Handlers\TookanHandler;
use Kanvas\Connectors\Tookan\Services\TookanService;
use Kanvas\Regions\Models\Regions;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\TestCase;

final class TookanIntegrationTest extends TestCase
{
    use HasIntegrationCompany;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('GITHUB_ACTIONS')) {
            $this->markTestSkipped('Tookan integration tests are skipped in CI');
        }
    }

    public function testCreateTask(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();
        $region = Regions::getDefault($company ?? $company, $app);

        $app->set(ConfigurationEnum::API_KEY->value, env('TEST_TOOKAN_API_KEY'));
        $app->set(ConfigurationEnum::BASE_URL->value, env('TEST_TOOKAN_BASE_URL', ConfigurationEnum::SANDBOX_URL->value));

        $this->setIntegration(
            $app,
            IntegrationsEnum::TOOKAN,
            TookanHandler::class,
            $company,
            $user
        );

        $customerDetail = new CustomerDetail(
            name: 'John Doe',
            phone: '9876543210',
            email: 'john.doe@example.com',
            address: '123 Main Street, New York, NY 10001',
            latitude: 40.7128,
            longitude: -74.0060
        );

        $deliveryTime = now()->addHours(2);

        $taskDetail = new TaskDetail(
            order_id: rand(10000, 99999),
            job_description: 'Deliver package to customer',
            customer: $customerDetail,
            job_delivery_datetime: $deliveryTime->format('m/d/Y H:i'),
            job_pickup_datetime: $deliveryTime->copy()->subMinutes(30)->format('m/d/Y H:i'),
            team_id: 0,
            timezone: '330',
            has_delivery: true,
            has_pickup: true,
            layout_type: '0',
            auto_assignment: false
        );

        $tookanService = new TookanService($app, $company);

        $result = $tookanService->createTask($taskDetail);

        $this->assertArrayHasKey('job_id', $result);
        $this->assertArrayHasKey('job_status', $result);
        $this->assertArrayHasKey('tracking_link', $result);
        $this->assertNotNull($result['job_id']);
    }

    public function testGetTaskDetails(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();
        $region = Regions::getDefault($company ?? $company, $app);

        $app->set(ConfigurationEnum::API_KEY->value, env('TEST_TOOKAN_API_KEY'));
        $app->set(ConfigurationEnum::BASE_URL->value, env('TEST_TOOKAN_BASE_URL', ConfigurationEnum::SANDBOX_URL->value));

        $this->setIntegration(
            $app,
            IntegrationsEnum::TOOKAN,
            TookanHandler::class,
            $company,
            $user
        );

        $customerDetail = new CustomerDetail(
            name: 'John Doe',
            phone: '9876543210',
            email: 'john.doe@example.com',
            address: '123 Main Street, New York, NY 10001',
            latitude: 40.7128,
            longitude: -74.0060
        );

        $deliveryTime = now()->addHours(2);

        $taskDetail = new TaskDetail(
            order_id: rand(10000, 99999),
            job_description: 'Deliver package to customer',
            customer: $customerDetail,
            job_delivery_datetime: $deliveryTime->format('m/d/Y H:i'),
            job_pickup_datetime: $deliveryTime->copy()->subMinutes(30)->format('m/d/Y H:i'),
            team_id: 0,
            timezone: '330',
            has_delivery: true,
            has_pickup: true,
            layout_type: '0',
            auto_assignment: false
        );

        $tookanService = new TookanService($app, $company);
        $createResult = $tookanService->createTask($taskDetail);
        $this->assertArrayHasKey('job_id', $createResult);

        $jobId = (int) $createResult['job_id'];

        $taskDetails = $tookanService->getTaskDetails($jobId);

        $this->assertIsArray($taskDetails);
        $this->assertNotEmpty($taskDetails);
    }

    public function testUpdateTask(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();
        $region = Regions::getDefault($company ?? $company, $app);

        $app->set(ConfigurationEnum::API_KEY->value, env('TEST_TOOKAN_API_KEY'));
        $app->set(ConfigurationEnum::BASE_URL->value, env('TEST_TOOKAN_BASE_URL', ConfigurationEnum::SANDBOX_URL->value));

        $this->setIntegration(
            $app,
            IntegrationsEnum::TOOKAN,
            TookanHandler::class,
            $company,
            $user
        );

        $customerDetail = new CustomerDetail(
            name: 'Bob Johnson',
            phone: '1555123456',
            email: 'bob.johnson@example.com',
            address: '789 Broadway, New York, NY 10003'
        );

        $taskDetail = new TaskDetail(
            order_id: rand(10000, 99999),
            job_description: 'Deliver food order',
            customer: $customerDetail,
            job_delivery_datetime: now()->addHours(1)->format('m/d/Y H:i'),
            timezone: '330',
            has_delivery: true,
            layout_type: '0'
        );

        $tookanService = new TookanService($app, $company);

        $createResult = $tookanService->createTask($taskDetail);
        $jobId = (int) $createResult['job_id'];

        $updateData = [
            'job_description' => 'Deliver food order - UPDATED',
            'job_status' => 1,
        ];

        $updateResult = $tookanService->updateTask($jobId, $updateData);

        $this->assertIsArray($updateResult);
    }

    public function testDeleteTask(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();
        $region = Regions::getDefault($company ?? $company, $app);

        $app->set(ConfigurationEnum::API_KEY->value, env('TEST_TOOKAN_API_KEY'));
        $app->set(ConfigurationEnum::BASE_URL->value, env('TEST_TOOKAN_BASE_URL', ConfigurationEnum::SANDBOX_URL->value));

        $this->setIntegration(
            $app,
            IntegrationsEnum::TOOKAN,
            TookanHandler::class,
            $company,
            $user
        );

        $customerDetail = new CustomerDetail(
            name: 'Test User',
            phone: '1555000000',
            email: 'test@example.com',
            address: 'Test Address'
        );

        $taskDetail = new TaskDetail(
            order_id: rand(10000, 99999),
            job_description: 'Test task for deletion',
            customer: $customerDetail,
            job_delivery_datetime: now()->addHours(1)->format('m/d/Y H:i'),
            timezone: '330',
            has_delivery: true,
            layout_type: '0'
        );

        $tookanService = new TookanService($app, $company);

        $createResult = $tookanService->createTask($taskDetail);
        $jobId = (int) $createResult['job_id'];

        $deleteResult = $tookanService->deleteTask($jobId);

        $this->assertArrayHasKey('success', $deleteResult);
        $this->assertArrayHasKey('message', $deleteResult);
    }

    public function testCreateMultipleTasks(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();
        $region = Regions::getDefault($company ?? $company, $app);

        $app->set(ConfigurationEnum::API_KEY->value, env('TEST_TOOKAN_API_KEY'));
        $app->set(ConfigurationEnum::BASE_URL->value, env('TEST_TOOKAN_BASE_URL', ConfigurationEnum::SANDBOX_URL->value));

        $this->setIntegration(
            $app,
            IntegrationsEnum::TOOKAN,
            TookanHandler::class,
            $company,
            $user
        );

        $tookanService = new TookanService($app, $company);

        // Create two pickup locations (restaurants)
        $pickups = [
            new PickupDetail(
                address: 'Restaurant A, Main Street, NY',
                latitude: 40.7580,
                longitude: -73.9855,
                time: now()->addHour()->format('Y-m-d H:i:s'),
                phone: '9876543210',
                job_description: 'Pick up food order from Restaurant A',
                name: 'Restaurant A',
                order_id: (string) rand(10000, 99999),
                email: 'restauranta@example.com'
            ),
            new PickupDetail(
                address: 'Restaurant B, Second Avenue, NY',
                latitude: 40.7200,
                longitude: -73.9900,
                time: now()->addMinutes(90)->format('Y-m-d H:i:s'),
                phone: '9876543211',
                job_description: 'Pick up food order from Restaurant B',
                name: 'Restaurant B',
                order_id: (string) rand(10000, 99999),
                email: 'restaurantb@example.com'
            ),
        ];

        // Create delivery location (customer)
        $deliveries = [
            new DeliveryDetail(
                address: '123 Customer Street, NY',
                latitude: 40.7450,
                longitude: -73.9800,
                time: now()->addHours(2)->format('Y-m-d H:i:s'),
                phone: '9876543299',
                job_description: 'Deliver food order to customer',
                name: 'Customer Home',
                order_id: (string) rand(10000, 99999),
                email: 'customer@example.com'
            ),
        ];

        // Create task with multiple pickups and delivery
        $task = new TaskMultipleDetail(
            pickups: $pickups,
            deliveries: $deliveries,
            team_id: 0,
            timezone: '330',
            has_pickup: true,
            has_delivery: true,
            auto_assignment: false
        );

        $result = $tookanService->createMultipleTasks($task);

        // Verify response
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }
}
