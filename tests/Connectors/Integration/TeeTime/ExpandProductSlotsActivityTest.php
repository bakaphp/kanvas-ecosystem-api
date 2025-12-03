<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\TeeTime;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\TeeTime\Handlers\TeeTimeHandler;
use Kanvas\Connectors\TeeTime\Workflows\Activities\ExpandProductSlotsActivity;
use Kanvas\Event\Events\Jobs\GenerateTimeSlots;
use Kanvas\Event\Events\Models\ScheduleRules;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Regions\Models\Regions;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

final class ExpandProductSlotsActivityTest extends TestCase
{
    use HasIntegrationCompany;
    use InventoryCases;

    public function testProductCreateWithOperationDaysCreatesScheduleRules(): void
    {
        Queue::fake();

        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();
        $region = Regions::getDefault($company, $app);

        $this->setIntegration(
            $app,
            IntegrationsEnum::TEE_TIME,
            TeeTimeHandler::class,
            $company,
            $user
        );

        // Create a product with operation days attribute
        $operationDays = [
            'monday' => [
                'active' => true,
                'open' => '07:00 AM',
                'close' => '06:00 PM',
            ],
            'tuesday' => [
                'active' => true,
                'open' => '07:00 AM',
                'close' => '06:00 PM',
            ],
            'wednesday' => [
                'active' => false,
                'open' => '07:00 AM',
                'close' => '06:00 PM',
            ],
            'friday' => [
                'active' => true,
                'open' => '08:00 AM',
                'close' => '05:00 PM',
            ],
        ];

        $productResponse = $this->createProduct(attributes: [
            [
                'name' => 'displayOperationDays',
                'value' => $operationDays,
            ],
        ])->json();

        $productResponse = $productResponse['data']['createProduct'];
        $product = Products::find($productResponse['id']);
        $variant = $product->variants()->first();

        // Execute the activity
        $activity = new ExpandProductSlotsActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );

        $result = $activity->execute($product, $app, [
            'currentEventTypeName' => WorkflowEnum::CREATED->value,
            'slot_duration_minutes' => 15,
            'lead_time_min' => 30,
            'cutoff_time_min' => 60,
        ]);

        // Assert activity executed successfully
        $this->assertEquals('success', $result['status']);
        $this->assertEquals('Product schedule rules created successfully', $result['message']);
        $this->assertEquals(3, $result['schedule_rules_created']); // Monday, Tuesday, Friday

        // Assert schedule rules were created
        $scheduleRules = ScheduleRules::where('resources_id', $variant->getId())
            ->where('resources_type', $variant->getMorphClass())
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->get();

        $this->assertCount(3, $scheduleRules);

        // Assert each rule has correct metadata
        $createdDays = $scheduleRules->pluck('metadata.operation_day')->toArray();
        $this->assertContains('monday', $createdDays);
        $this->assertContains('tuesday', $createdDays);
        $this->assertContains('friday', $createdDays);
        $this->assertNotContains('wednesday', $createdDays); // Not active

        // Assert RRULE format is correct
        foreach ($scheduleRules as $rule) {
            $this->assertStringContainsString('DTSTART:', $rule->rrule);
            $this->assertStringContainsString('RRULE:FREQ=WEEKLY;BYDAY=', $rule->rrule);
            $this->assertNotNull($rule->day_rrule);
            $this->assertStringContainsString('DTSTART:', $rule->day_rrule);
            $this->assertStringContainsString('RRULE:FREQ=MINUTELY;INTERVAL=15', $rule->day_rrule);
        }

        // Assert slot duration and timing parameters
        foreach ($scheduleRules as $rule) {
            $this->assertEquals(15, $rule->slot_duration_min);
            $this->assertEquals(30, $rule->lead_time_min);
            $this->assertEquals(60, $rule->cutoff_time_min);
        }

        // Assert GenerateTimeSlots job was dispatched for each rule
        Queue::assertPushed(GenerateTimeSlots::class, 3);
    }

    public function testProductUpdateWithOperationDaysReplacesScheduleRules(): void
    {
        Queue::fake();

        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();

        $this->setIntegration(
            $app,
            IntegrationsEnum::TEE_TIME,
            TeeTimeHandler::class,
            $company,
            $user
        );

        // Create initial product with operation days
        $initialOperationDays = [
            'monday' => [
                'active' => true,
                'open' => '07:00 AM',
                'close' => '06:00 PM',
            ],
            'tuesday' => [
                'active' => true,
                'open' => '07:00 AM',
                'close' => '06:00 PM',
            ],
        ];

        $productResponse = $this->createProduct(attributes: [
            [
                'name' => 'displayOperationDays',
                'value' => $initialOperationDays,
            ],
        ])->json();

        $product = Products::find($productResponse['data']['createProduct']['id']);
        $variant = $product->variants()->first();

        // Execute initial creation
        $activity = new ExpandProductSlotsActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );

        $activity->execute($product, $app, [
            'currentEventTypeName' => WorkflowEnum::CREATED->value,
        ]);

        // Verify initial rules
        $initialRules = ScheduleRules::where('resources_id', $variant->getId())
            ->where('resources_type', $variant->getMorphClass())
            ->get();
        $this->assertCount(2, $initialRules);

        // Update operation days
        $updatedOperationDays = [
            'wednesday' => [
                'active' => true,
                'open' => '08:00 AM',
                'close' => '05:00 PM',
            ],
            'thursday' => [
                'active' => true,
                'open' => '08:00 AM',
                'close' => '05:00 PM',
            ],
            'friday' => [
                'active' => true,
                'open' => '08:00 AM',
                'close' => '05:00 PM',
            ],
        ];

        // Update product attribute
        $product->setCustomFields(['displayOperationDays' => $updatedOperationDays]);
        $product->refresh();

        // Execute update
        $result = $activity->execute($product, $app, [
            'currentEventTypeName' => WorkflowEnum::UPDATED->value,
        ]);

        // Assert old rules were deleted and new ones created
        $updatedRules = ScheduleRules::where('resources_id', $variant->getId())
            ->where('resources_type', $variant->getMorphClass())
            ->get();

        $this->assertCount(3, $updatedRules);
        $this->assertEquals(3, $result['schedule_rules_created']);

        // Assert new days are present
        $updatedDays = $updatedRules->pluck('metadata.operation_day')->toArray();
        $this->assertContains('wednesday', $updatedDays);
        $this->assertContains('thursday', $updatedDays);
        $this->assertContains('friday', $updatedDays);
        $this->assertNotContains('monday', $updatedDays);
        $this->assertNotContains('tuesday', $updatedDays);
    }

    public function testProductDeleteRemovesScheduleRules(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();

        $this->setIntegration(
            $app,
            IntegrationsEnum::TEE_TIME,
            TeeTimeHandler::class,
            $company,
            $user
        );

        // Create product with operation days
        $operationDays = [
            'monday' => [
                'active' => true,
                'open' => '07:00 AM',
                'close' => '06:00 PM',
            ],
        ];

        $productResponse = $this->createProduct(attributes: [
            [
                'name' => 'displayOperationDays',
                'value' => $operationDays,
            ],
        ])->json();

        $product = Products::find($productResponse['data']['createProduct']['id']);
        $variant = $product->variants()->first();

        // Execute creation
        $activity = new ExpandProductSlotsActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );

        $activity->execute($product, $app, [
            'currentEventTypeName' => WorkflowEnum::CREATED->value,
        ]);

        // Verify rules exist
        $rules = ScheduleRules::where('resources_id', $variant->getId())
            ->where('resources_type', $variant->getMorphClass())
            ->get();
        $this->assertCount(1, $rules);

        // Execute delete
        $result = $activity->execute($product, $app, [
            'currentEventTypeName' => WorkflowEnum::DELETED->value,
        ]);

        // Assert rules were deleted
        $rulesAfterDelete = ScheduleRules::where('resources_id', $variant->getId())
            ->where('resources_type', $variant->getMorphClass())
            ->get();
        $this->assertCount(0, $rulesAfterDelete);
        $this->assertEquals('success', $result['status']);
    }

    public function testProductWithoutVariantFails(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();

        $this->setIntegration(
            $app,
            IntegrationsEnum::TEE_TIME,
            TeeTimeHandler::class,
            $company,
            $user
        );

        // Create product
        $productResponse = $this->createProduct(attributes: [
            [
                'name' => 'displayOperationDays',
                'value' => [
                    'monday' => [
                        'active' => true,
                        'open' => '07:00 AM',
                        'close' => '06:00 PM',
                    ],
                ],
            ],
        ])->json();

        $product = Products::find($productResponse['data']['createProduct']['id']);

        // Delete all variants to simulate no variants
        $product->variants()->delete();
        $product->refresh();

        // Execute activity
        $activity = new ExpandProductSlotsActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );

        $result = $activity->execute($product, $app, [
            'currentEventTypeName' => WorkflowEnum::CREATED->value,
        ]);

        // Assert failure
        $this->assertEquals('failed', $result['status']);
        $this->assertEquals('No variant found for product', $result['message']);
    }
}
