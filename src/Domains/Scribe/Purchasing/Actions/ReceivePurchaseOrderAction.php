<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Purchasing\Actions;

use Baka\Support\Number;
use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\DocumentSequences\Enums\DocumentTypeEnum;
use Kanvas\Scribe\DocumentSequences\Services\DocumentNumberAllocatorService;
use Kanvas\Scribe\Ledger\Actions\Concerns\LocksDocumentRow;
use Kanvas\Scribe\Purchasing\Enums\PurchaseOrderConfigurationEnum;
use Kanvas\Scribe\Purchasing\Enums\PurchaseOrderStatusEnum;
use Kanvas\Scribe\Purchasing\Exceptions\InvalidPurchaseOrderTransitionException;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrder;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrderLine;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrderReceipt;
use Kanvas\Scribe\Purchasing\Services\PurchaseOrderStateMachineService;
use Kanvas\Users\Models\Users;

/**
 * Records goods received against a sent purchase order: what arrived on each line, on a receipt numbered from its
 * own sequence. The order's lines keep their received and open quantities, and the order becomes partially received
 * until every line is covered, then received. More than the open quantity is refused, within the company's
 * over-receipt tolerance when it sets one.
 */
class ReceivePurchaseOrderAction
{
    use LocksDocumentRow;

    public const string NUMBER_PREFIX = 'GR-';

    /**
     * @param list<array{line_number: int, quantity: float|int}> $lines
     */
    public function __construct(
        protected readonly PurchaseOrder $order,
        protected readonly Users $user,
        protected readonly Carbon $receivedOn,
        protected readonly array $lines,
        protected readonly ?string $reference = null,
        protected readonly ?string $notes = null,
        protected readonly DocumentNumberAllocatorService $allocator = new DocumentNumberAllocatorService(),
        protected readonly PurchaseOrderStateMachineService $stateMachine = new PurchaseOrderStateMachineService(),
    ) {
    }

    public function execute(): PurchaseOrderReceipt
    {
        // Two receipts of one order take turns: each is checked against what the one before left open.
        return $this->withRowLocked($this->order, function (): PurchaseOrderReceipt {
            $this->assertReceivable();
            $quantities = $this->quantitiesByLine();

            $receipt = new PurchaseOrderReceipt();
            $receipt->apps_id = $this->order->apps_id;
            $receipt->companies_id = $this->order->companies_id;
            $receipt->purchase_order_id = $this->order->getId();
            $receipt->receipt_number = $this->allocator->allocate(
                $receipt->apps_id,
                $receipt->companies_id,
                DocumentTypeEnum::GOODS_RECEIPT,
                self::NUMBER_PREFIX,
            );
            $receipt->received_on = $this->receivedOn;
            $receipt->reference = Str::trimToNull($this->reference);
            $receipt->notes = Str::trimToNull($this->notes);
            $receipt->users_id = $this->user->getId();
            $receipt->saveOrFail();

            foreach ($this->order->lines as $line) {
                $quantity = $quantities[$line->line_number] ?? null;

                if ($quantity === null) {
                    continue;
                }

                $receipt->lines()->create([
                    'purchase_order_line_id' => $line->id,
                    'quantity' => $quantity,
                ]);

                $line->received_qty = (float) $line->received_qty + $quantity;
                $line->open_qty = max(0.0, (float) $line->open_qty - $quantity);
                $line->save();
            }

            $this->moveOrder();

            return $receipt->fresh(['lines']);
        });
    }

    private function assertReceivable(): void
    {
        if (! $this->order->isIssuedByCompany()) {
            throw InvalidPurchaseOrderTransitionException::notIssuedByCompany($this->order, 'received against');
        }

        $receivable = [PurchaseOrderStatusEnum::SENT, PurchaseOrderStatusEnum::PARTIALLY_RECEIVED];

        if (! in_array($this->order->document_status, $receivable, true)) {
            throw new InvalidPurchaseOrderTransitionException(
                "Purchase order {$this->order->order_number} is {$this->order->document_status->value}: "
                . 'goods can only be received against a sent order.'
            );
        }
    }

    /**
     * The quantity received per order line, validated against what is still open.
     *
     * @return array<int, float>
     */
    private function quantitiesByLine(): array
    {
        if ($this->lines === []) {
            throw new ValidationException('A receipt needs at least one line.');
        }

        $tolerance = (float) $this->order->company->get(
            PurchaseOrderConfigurationEnum::OVER_RECEIPT_TOLERANCE_PERCENT->value
        );
        $orderLines = $this->order->lines->keyBy('line_number');
        $quantities = [];

        foreach ($this->lines as $entry) {
            $number = (int) $entry['line_number'];
            $quantity = (float) $entry['quantity'];
            /** @var PurchaseOrderLine|null $line */
            $line = $orderLines->get($number);

            if ($line === null) {
                throw new ValidationException("Purchase order {$this->order->order_number} has no line {$number}.");
            }

            if ($quantity <= 0 || isset($quantities[$number])) {
                throw new ValidationException("Line {$number} needs one quantity above zero.");
            }

            $allowed = (float) $line->open_qty * (1 + $tolerance / 100);

            if ($quantity > $allowed) {
                throw new ValidationException(sprintf(
                    'Line %d: %s received but only %s is open on the order.',
                    $number,
                    Number::withoutTrailingZeros($quantity, 6),
                    Number::withoutTrailingZeros((float) $line->open_qty, 6)
                ));
            }

            $quantities[$number] = $quantity;
        }

        return $quantities;
    }

    private function moveOrder(): void
    {
        $everythingArrived = $this->order->lines()->where('open_qty', '>', 0)->doesntExist();
        $target = $everythingArrived
            ? PurchaseOrderStatusEnum::RECEIVED
            : PurchaseOrderStatusEnum::PARTIALLY_RECEIVED;

        $this->stateMachine->assertTransition($this->order, $target);

        $this->order->document_status = $target;
        $this->order->saveOrFail();
    }
}
