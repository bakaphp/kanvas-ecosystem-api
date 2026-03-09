<?php

declare(strict_types=1);

namespace Kanvas\Connectors\TeeTime\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Event\Events\Actions\CreateScheduleRulesFromOperationDaysAction;
use Kanvas\Event\Events\Models\ScheduleRules;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

class ExpandProductSlotsActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $event, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $event,
            app: $app,
            integration: IntegrationsEnum::TEE_TIME,
            additionalParams: $params,
            integrationOperation: function ($product, $app, $integrationCompany, $additionalParams) use ($params) {
                $eventName = $additionalParams['currentEventTypeName'] ?? null;
                $createdRules = [];
                $variant = $product->variants()->first();

                if (in_array($eventName, [WorkflowEnum::UPDATED->value, WorkflowEnum::CREATED->value])) {
                    // Get operation days from product attributes
                    $operationDays = $this->getOperationDaysFromProduct($product);

                    if (! empty($operationDays)) {
                        // Get configuration from params or use defaults
                        $slotDurationMinutes = $params['slot_duration_minutes'] ?? 60;
                        $capacityOverride = $params['capacity_override'] ?? null;
                        $leadTimeMin = $params['lead_time_min'] ?? 0;
                        $cutoffTimeMin = $params['cutoff_time_min'] ?? 0;

                        if (! $variant) {
                            return [
                                'event' => $product->getId(),
                                'status' => 'failed',
                                'event_name' => $eventName,
                                'message' => 'No variant found for product',
                                'data' => $product->toArray(),
                                'response' => null,
                            ];
                        }

                        // Create the action
                        $action = new CreateScheduleRulesFromOperationDaysAction(
                            resource: $variant,
                            app: $app,
                            company: $product->company,
                            operationDays: $operationDays,
                            slotDurationMinutes: $slotDurationMinutes,
                            capacityOverride: $capacityOverride,
                            leadTimeMin: $leadTimeMin,
                            cutoffTimeMin: $cutoffTimeMin
                        );

                        // Create schedule rules (existing rules are automatically cleared during execution)
                        $createdRules = $action->execute();
                    }
                }

                if ($eventName === WorkflowEnum::DELETED->value) {
                    // Delete all schedule rules and time slots for this product
                    $this->deleteScheduleRulesForProduct($product, $app);
                }

                return [
                    'product' => $product->getId(),
                    'variant' => $variant->getId(),
                    'status' => 'success',
                    'event_name' => $eventName,
                    'message' => 'Product schedule rules created successfully',
                    'schedule_rules_created' => count($createdRules),
                    'data' => $product->toArray(),
                    'response' => [
                        'product_id' => $product->getId(),
                        'schedule_rules_created' => count($createdRules),
                        'rules' => array_map(fn ($rule) => [
                            'id' => $rule->id,
                            'day' => $rule->metadata['operation_day'] ?? null,
                            'rrule' => $rule->rrule,
                        ], $createdRules),
                    ],
                ];
            },
            company: $event->company,
        );
    }

    /**
     * Extract operation days from product attributes.
     */
    protected function getOperationDaysFromProduct(Products $product): array
    {
        $operationDays = $product->getAttributeByName('displayOperationDays')?->value ?? [];

        // Look for operation_days in different possible locations
        if (! empty($operationDays)) {
            return is_string($operationDays) ? json_decode($operationDays, true) : $operationDays;
        }

        return [];
    }

    /**
     * Delete all schedule rules and associated time slots for a product's variant.
     */
    protected function deleteScheduleRulesForProduct(Model $product, AppInterface $app): void
    {
        $variant = $product->variants()->first();

        if (! $variant) {
            return;
        }

        $scheduleRules = ScheduleRules::where('resources_id', $variant->getId())
            ->where('resources_type', $variant->getMorphClass())
            ->where('apps_id', $app->getId())
            ->whereJsonContains('metadata->created_from', 'operation_days')
            ->get();

        foreach ($scheduleRules as $rule) {
            // Delete upcoming time slots
            $rule->deleteUpcomingTimeSlots();
            // Delete the rule
            $rule->delete();
        }
    }
}
