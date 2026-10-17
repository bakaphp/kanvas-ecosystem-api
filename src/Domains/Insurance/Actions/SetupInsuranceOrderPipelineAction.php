<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Insurance\Enums\InsuranceOrderStatusEnum;
use Kanvas\Insurance\Enums\InsuranceOrderTypeEnum;
use Kanvas\Insurance\Services\InsuranceCarnet;
use Kanvas\Souk\Orders\DataTransferObject\OrderReceipt;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Souk\Orders\Models\OrderStatusTransitions;
use Kanvas\Souk\Orders\Models\OrderTypes;

class SetupInsuranceOrderPipelineAction
{
    public function __construct(
        private readonly Apps $app,
    ) {
    }

    public function execute(): OrderTypes
    {
        $orderType = OrderTypes::firstOrCreate(
            ['apps_id' => $this->app->getId(), 'name' => InsuranceOrderTypeEnum::INSURANCE->value],
            ['is_default' => false, 'companies_id' => 0]
        );

        $statuses = $this->seedStatuses($orderType);
        $this->seedTransitions($orderType, $statuses);

        $orderType->update([
            'total_statuses' => count($statuses),
            'config' => array_merge(
                (array) $orderType->config,
                [
                    OrderReceipt::CONFIG_KEY => InsuranceCarnet::receipt()->toConfig(),
                    OrderTypes::ALLOWED_PAYMENT_PROCESSORS => config('insurance.payment_processors', ['azul']),
                ]
            ),
        ]);

        new SeedInsuranceTemplatesAction($this->app)->execute();

        return $orderType;
    }

    private function seedStatuses(OrderTypes $orderType): array
    {
        $statuses = [];

        OrderStatus::withoutSyncingToSearch(function () use ($orderType, &$statuses): void {
            foreach (InsuranceOrderStatusEnum::cases() as $status) {
                $statuses[$status->value] = OrderStatus::firstOrCreate(
                    ['apps_id' => $this->app->getId(), 'order_types_id' => $orderType->getId(), 'slug' => $status->value],
                    [
                        'name' => $status->label(),
                        'is_default' => $status->isDefault(),
                        'is_final' => $status->isFinal(),
                        'sequence' => $status->sequence(),
                        'companies_id' => 0,
                    ]
                );
            }
        });

        return $statuses;
    }

    private function seedTransitions(OrderTypes $orderType, array $statuses): void
    {
        foreach (InsuranceOrderStatusEnum::cases() as $from) {
            foreach ($from->transitionsTo() as $to) {
                OrderStatusTransitions::firstOrCreate(
                    [
                        'order_types_id' => $orderType->getId(),
                        'from_status_id' => $statuses[$from->value]->getId(),
                        'to_status_id' => $statuses[$to->value]->getId(),
                    ],
                    [
                        'name' => $statuses[$from->value]->name . ' → ' . $statuses[$to->value]->name,
                        'companies_id' => 0,
                    ]
                );
            }
        }
    }
}
