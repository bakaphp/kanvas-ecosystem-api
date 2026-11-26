<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesOrders\Services;

use Kanvas\Scribe\SalesOrders\Credit\CustomerCreditService;
use Kanvas\Scribe\SalesOrders\Models\SalesOrderShipment;

/**
 * What has to happen, and who does it, counted in days from the bill of lading date: the follow-up runs from the
 * shipping notice to holding the customer's new orders, and the invoice falls due the order's payment term after it.
 */
class SalesOrderCollectionPlanService
{
    /**
     * @var list<array{day: int, action: string, owner: string}>
     */
    private const array STEPS = [
        [
            'day' => 0,
            'action' => 'Shipping notice; Collections creates the collection order due at the bill of lading date '
                . 'plus the payment term',
            'owner' => 'Operations, then Collections',
        ],
        [
            'day' => 30,
            'action' => 'Confirm the invoice is registered in the customer\'s accounts payable',
            'owner' => 'Commercial',
        ],
        [
            'day' => 40,
            'action' => 'Friendly reminder with the invoice and the bank details',
            'owner' => 'Collections',
        ],
        [
            'day' => 45,
            'action' => 'Confirm the payment date and ask for the proof of payment',
            'owner' => 'Commercial',
        ],
        [
            'day' => 55,
            'action' => 'No payment: call the customer',
            'owner' => 'Commercial',
        ],
        [
            'day' => 65,
            'action' => 'No payment: escalate to the Commercial Director',
            'owner' => 'Direction',
        ],
    ];

    /**
     * @return list<array{day: int, date: string, action: string, owner: string}>
     */
    public function plan(SalesOrderShipment $shipment): array
    {
        $steps = [
            ...self::STEPS,
            [
                'day' => $shipment->payment_terms_days,
                'action' => 'Due: reconcile the payment and update the collections register and the payment '
                    . 'scorecard',
                'owner' => 'Collections',
            ],
            // The same day the credit gates start refusing the customer, so the plan never promises another one.
            [
                'day' => CustomerCreditService::firstHoldDay(
                    CustomerCreditService::holdDaysFor($shipment->salesOrder->company),
                    $shipment->payment_terms_days
                ),
                'action' => 'No payment: no new order of the customer is confirmed or released',
                'owner' => 'Gates 1 and 3',
            ],
        ];

        usort($steps, static fn (array $first, array $second): int => $first['day'] <=> $second['day']);

        return array_map(
            static fn (array $step): array => [
                'day' => $step['day'],
                'date' => $shipment->bl_date->copy()->addDays($step['day'])->toDateString(),
                'action' => $step['action'],
                'owner' => $step['owner'],
            ],
            $steps
        );
    }
}
