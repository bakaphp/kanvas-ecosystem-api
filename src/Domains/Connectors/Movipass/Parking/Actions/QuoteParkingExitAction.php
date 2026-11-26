<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Parking\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Parking\DataTransferObject\ParkingQuote;
use Kanvas\Connectors\Movipass\Parking\ParkingOperatorResolver;
use Kanvas\Connectors\Movipass\Parking\Support\ParkingMetadata;
use Kanvas\Connectors\Movipass\Parking\Support\ParkingQuotePayment;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;

class QuoteParkingExitAction
{
    public function __construct(
        protected readonly Order $order,
    ) {
    }

    public function execute(): ParkingQuote
    {
        return DB::connection('commerce')->transaction(function (): ParkingQuote {
            $this->order->newQuery()->lockForUpdate()->findOrFail($this->order->getId());
            $this->order->refresh();

            $this->assertQuotable();

            $quote = ParkingOperatorResolver::forOrder($this->order)->quote($this->order, Carbon::now());

            $this->reprice($quote);
            ParkingMetadata::put($this->order, [
                ParkingMetadata::QUOTE => [
                    'amount' => $quote->amount,
                    'amount_due' => round((float) $this->order->total_net_amount, 2),
                    'reference' => $quote->reference,
                    'quoted_at' => $quote->quotedAt->toIso8601String(),
                ],
            ]);

            return $quote;
        });
    }

    protected function assertQuotable(): void
    {
        if (! ParkingMetadata::hasEntered($this->order)) {
            throw new ValidationException('This order is not a walk-in parking visit.');
        }

        if ($this->order->orderStatus?->slug !== MovipassOrderStatusEnum::ACTIVE->slug()) {
            throw new ValidationException('Only an active parking visit can be quoted.');
        }

        if (ParkingQuotePayment::hasPaidPayment($this->order)) {
            throw new ValidationException('The exit is already paid; the quote can no longer change.');
        }

        if (ParkingQuotePayment::hasOpenPayment($this->order)) {
            throw new ValidationException('A payment is in progress for this visit; wait for it to finish before quoting again.');
        }
    }

    protected function reprice(ParkingQuote $quote): void
    {
        $items = $this->order->items()->get();

        if ($items->count() !== 1) {
            throw new ValidationException('A parking order must have exactly one line to be priced.');
        }

        $parkingLine = $items->first();

        $parkingLine->quantity = 1;
        $parkingLine->unit_price_net_amount = $quote->amount;
        $parkingLine->unit_price_gross_amount = $quote->amount;
        $parkingLine->saveOrFail();

        $this->order->calculateTotal();
    }
}
