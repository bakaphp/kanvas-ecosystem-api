<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Kanvas\CustomFields\Models\AppsCustomFields;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Insurance\Enums\InsuranceOrderTypeEnum;
use Kanvas\Insurance\Enums\InsuranceStatusEnum;
use Kanvas\Insurance\Services\InsurancePolicyReminders;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Throwable;

class SendInsurancePolicyRemindersAction
{
    private const int CHUNK_SIZE = 100;

    public function __construct(
        private readonly AppInterface $app,
        private readonly Carbon $today,
    ) {
    }

    public function execute(): int
    {
        $daysByDate = $this->daysByDate();
        $endsAt = $this->fields(InsuranceCustomFieldEnum::POLICY_ENDS_AT)
            ->whereIn('value', array_keys($daysByDate))
            ->pluck('value', 'entity_id')
            ->all();
        $active = $this->activeOrderIds($endsAt);
        $sent = $this->sentMarkers($active);
        $due = [];

        foreach ($active as $orderId) {
            $date = (string) $endsAt[$orderId];
            $marker = InsurancePolicyReminders::marker($date, $daysByDate[$date]);

            if (! in_array($marker, $sent[$orderId] ?? [], true)) {
                $due[] = $orderId;
            }
        }

        if ($due === []) {
            return 0;
        }

        $orderType = OrderTypes::query()
            ->where('apps_id', $this->app->getId())
            ->where('name', InsuranceOrderTypeEnum::INSURANCE->value)
            ->firstOrFail();
        $total = 0;

        Order::query()
            ->fromApp($this->app)
            ->notDeleted()
            ->where('order_types_id', $orderType->getId())
            ->whereIn('id', $due)
            ->with('user')
            ->chunkById(self::CHUNK_SIZE, function ($orders) use ($endsAt, $daysByDate, $sent, &$total): void {
                foreach ($orders as $order) {
                    $date = (string) $endsAt[$order->getId()];

                    try {
                        new SendInsurancePolicyReminderAction(
                            $order,
                            $date,
                            $daysByDate[$date],
                            $sent[$order->getId()] ?? [],
                        )->execute();
                        $total++;
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                }
            });

        return $total;
    }

    private function daysByDate(): array
    {
        $daysByDate = [];

        foreach (InsurancePolicyReminders::days() as $days) {
            $daysByDate[$this->today->copy()->addDays($days)->toDateString()] = $days;
        }

        return $daysByDate;
    }

    private function activeOrderIds(array $endsAt): array
    {
        if ($endsAt === []) {
            return [];
        }

        return array_keys(array_filter(
            $this->entityValues(InsuranceCustomFieldEnum::STATUS, array_keys($endsAt)),
            fn (mixed $status): bool => $status === InsuranceStatusEnum::POLICY_ACTIVE->value
        ));
    }

    private function sentMarkers(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        return array_map(
            InsurancePolicyReminders::sentMarkers(...),
            $this->entityValues(InsuranceCustomFieldEnum::REMINDERS_SENT, $orderIds)
        );
    }

    private function entityValues(InsuranceCustomFieldEnum $field, array $orderIds): array
    {
        return $this->fields($field)->whereIn('entity_id', $orderIds)->pluck('value', 'entity_id')->all();
    }

    private function fields(InsuranceCustomFieldEnum $field): Builder
    {
        return AppsCustomFields::query()
            ->where('model_name', Order::class)
            ->where('name', $field->value)
            ->where('is_deleted', false);
    }
}
