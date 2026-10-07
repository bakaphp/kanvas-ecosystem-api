<?php

declare(strict_types=1);

namespace Tests\Insurance;

use Kanvas\Apps\Models\Apps;
use Kanvas\CustomFields\Models\AppsCustomFields;
use Kanvas\Insurance\Activities\CancelInsuranceOrderActivity;
use Kanvas\Insurance\Activities\InvoiceInsurancePolicyActivity;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Insurance\Enums\InsuranceStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Models\StoredWorkflow;
use Mockery;
use Tests\TestCase;

class InsuranceFlowActivitiesTest extends TestCase
{
    private function order(array $fields): Order
    {
        $stored = $fields;

        $order = Mockery::mock(Order::class)->makePartial();
        $order->shouldReceive('getId')->andReturn(10);
        $order->shouldReceive('get')->andReturnUsing(function (string $key) use (&$stored) {
            return $stored[$key] ?? null;
        });
        $order->shouldReceive('set')->andReturnUsing(function (string $key, mixed $value) use (&$stored): AppsCustomFields {
            $stored[$key] = $value;

            return Mockery::mock(AppsCustomFields::class);
        });

        return $order;
    }

    private function activity(string $class): object
    {
        return new $class(0, now()->toDateTimeString(), StoredWorkflow::make(), []);
    }

    public function testCancelSkipsOrdersThatAreNotInsurance(): void
    {
        $result = $this->activity(CancelInsuranceOrderActivity::class)
            ->execute($this->order([]), app(Apps::class));

        $this->assertSame('skipped', $result['status']);
    }

    public function testCancelRefusesOnceThePolicyExists(): void
    {
        $order = $this->order([InsuranceCustomFieldEnum::STATUS->value => InsuranceStatusEnum::POLICY_ACTIVE->value]);

        $result = $this->activity(CancelInsuranceOrderActivity::class)->execute($order, app(Apps::class));

        $this->assertStringContainsString('already exists', $result['message']);
        $this->assertSame(InsuranceStatusEnum::POLICY_ACTIVE->value, $order->get(InsuranceCustomFieldEnum::STATUS->value));
    }

    public function testCancelMarksTheOrderCancelledWhenNothingIsHeld(): void
    {
        $order = $this->order([InsuranceCustomFieldEnum::STATUS->value => InsuranceStatusEnum::QUOTED->value]);
        $order->shouldReceive('payments->where->orderByDesc->first')->andReturn(null);

        $result = $this->activity(CancelInsuranceOrderActivity::class)->execute($order, app(Apps::class));

        $this->assertSame('success', $result['status']);
        $this->assertFalse($result['paymentReleased']);
        $this->assertSame(InsuranceStatusEnum::CANCELLED->value, $order->get(InsuranceCustomFieldEnum::STATUS->value));
    }

    public function testInvoiceIsANoOpOnceAlreadyInvoiced(): void
    {
        $order = $this->order([InsuranceCustomFieldEnum::INVOICED_AT->value => '2026-10-07T10:00:00+00:00']);

        $result = $this->activity(InvoiceInsurancePolicyActivity::class)->execute($order, app(Apps::class));

        $this->assertSame('skipped', $result['status']);
    }

    public function testInvoiceRefusesWithoutAPolicyNumber(): void
    {
        $result = $this->activity(InvoiceInsurancePolicyActivity::class)
            ->execute($this->order([]), app(Apps::class));

        $this->assertStringContainsString('emit it first', $result['message']);
    }
}
